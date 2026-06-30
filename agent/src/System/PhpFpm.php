<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Per-vhost PHP-FPM: svaki vhost ima VLASTITI dedicirani FPM master kao zaseban
 * systemd servis (forge-fpm-<sys_user>.service) — ne dijeljeni php<ver>-fpm master.
 * Tek tako svaki vhost dobiva ZASEBAN cgroup (system.slice/forge-fpm-<id>.service),
 * pa CPUQuota/MemoryMax/TasksMax (drop-in) vrijede PO VHOSTU, a ne za cijelu PHP
 * verziju — to je srž "kvota bez CloudLinuxa". Per-vhost metrike (Scheduler) čitaju
 * isti cgroup.
 *
 * Socket path (/run/php/fpm-<sys_user>.sock) je NEPROMIJENJEN → nginx/Apache config
 * ostaje isti. Servis je keyiran na sys_user (NE na PHP verziju): promjena verzije
 * samo prepiše master conf i restarta isti servis.
 *
 * NAPOMENA (validacija): ova promjena dira systemd/php-fpm i MORA proći na čistom
 * Ubuntu 26.04 (multipass/LXD) prije produkcijskog deploya — vidi docs.
 */
final class PhpFpm
{
    private const DISABLE_FUNCTIONS = 'exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,dl';

    /** Per-vhost master + pool conf (NE u dijeljenom /etc/php/<ver>/fpm/pool.d). */
    private const FPM_DIR = '/etc/forgepanel/fpm';
    private const RUN_DIR = '/run/php';

    /** Optimizirani defaulti (veći od PHP stock vrijednosti) — korisnički override ih gazi. */
    private const DEFAULTS = [
        'memory_limit'        => '256M',
        'max_execution_time'  => '120',
        'max_input_time'      => '120',
        'post_max_size'       => '128M',
        'upload_max_filesize' => '128M',
        'max_input_vars'      => '5000',
        'opcache.enable'      => '1',
    ];

    /** Per-domena podesivi PHP ini-ovi (Plesk-style) + dozvoljeni format vrijednosti. */
    private const TUNABLES = [
        'memory_limit'        => '/^(-1|\d{1,6}[KMGkmg]?)$/',
        'max_execution_time'  => '/^\d{1,6}$/',
        'max_input_time'      => '/^-?\d{1,6}$/',
        'post_max_size'       => '/^\d{1,6}[KMGkmg]?$/',
        'upload_max_filesize' => '/^\d{1,6}[KMGkmg]?$/',
        'max_input_vars'      => '/^\d{1,6}$/',
        'opcache.enable'      => '/^[01]$/',
        'display_errors'      => '/^(On|Off|on|off|0|1)$/',
        'disable_functions'   => '/^[a-zA-Z0-9_,]{0,500}$/',
    ];

    /** systemd servis dediciranog FPM mastera za vhost. */
    public static function service(string $sys_user): string
    {
        return 'forge-fpm-' . $sys_user . '.service';
    }

    private static function confDir(string $sys_user): string
    {
        return self::FPM_DIR . '/' . $sys_user;
    }

    private static function unitPath(string $sys_user): string
    {
        return '/etc/systemd/system/' . self::service($sys_user);
    }

    public static function socketPath(string $sys_user): string
    {
        return self::RUN_DIR . '/fpm-' . $sys_user . '.sock';
    }

    /** Legacy (stari model): pool u dijeljenom php<ver>-fpm pool.d direktoriju. */
    public static function poolPath(string $php_version, string $sys_user): string
    {
        return "/etc/php/{$php_version}/fpm/pool.d/{$sys_user}.conf";
    }

    /**
     * Kreira/azurira dedicirani FPM servis vhosta i (re)starta ga. Idempotentno.
     * NE dira resource limite (oni idu kroz setLimits / systemd drop-in) — tako
     * promjena PHP postavki/verzije ne resetira kvote plana.
     *
     * @param array<string, mixed>|null $settings per-domena PHP override
     */
    public static function writePool(string $php_version, string $sys_user, string $vhost_root, ?array $settings = null): void
    {
        $dir = self::confDir($sys_user);
        if (!is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }
        if (!is_dir(self::RUN_DIR)) {
            mkdir(self::RUN_DIR, 0o755, true);
        }

        $pool_conf = $dir . '/pool.conf';
        $master_conf = $dir . '/php-fpm.conf';
        $unit_path = self::unitPath($sys_user);

        // Backup za rollback (ako vec postoji)
        $bk = [
            $pool_conf => is_file($pool_conf) ? file_get_contents($pool_conf) : null,
            $master_conf => is_file($master_conf) ? file_get_contents($master_conf) : null,
            $unit_path => is_file($unit_path) ? file_get_contents($unit_path) : null,
        ];

        file_put_contents($pool_conf, self::poolIni($php_version, $sys_user, $vhost_root, $settings));
        file_put_contents($master_conf, self::masterConf($sys_user, $vhost_root));
        file_put_contents($unit_path, self::unit($php_version, $sys_user));

        // Validacija configa dediciranog mastera prije (re)starta
        $test = Proc::run(["php-fpm{$php_version}", '-t', '-y', $master_conf]);
        if (!$test->ok()) {
            self::restore($bk);
            throw new \RuntimeException("php-fpm{$php_version} -t pao, config vraćen: " . trim($test->stderr));
        }

        // Migracija sa starog modela: makni legacy pool iz dijeljenog mastera (izbjegni
        // dvostruki bind na isti socket) i reloadaj te shared mastere.
        self::removeLegacyPools($sys_user);

        Systemd::daemonReload();
        $started = Proc::run(['systemctl', 'enable', '--now', self::service($sys_user)]);
        if (!$started->ok()) {
            // pokušaj restart (ako je vec bio enabled)
            $started = Proc::run(['systemctl', 'restart', self::service($sys_user)]);
        }
        if (!$started->ok() || !self::waitForSocket($sys_user)) {
            // rollback: vrati prethodne fileove (ili ukloni ako su bili novi) + reload
            self::restore($bk);
            Systemd::daemonReload();
            Proc::run(['systemctl', 'restart', self::service($sys_user)]);
            throw new \RuntimeException('FPM servis ' . self::service($sys_user) . ' se nije pokrenuo: ' . trim($started->stderr));
        }
    }

    /**
     * Per-vhost cgroup kvote (CPUQuota/MemoryMax/TasksMax) kroz systemd drop-in na
     * DEDICIRANOM servisu vhosta — vrijede po vhostu, ne za cijelu PHP verziju.
     */
    public static function setLimits(string $sys_user, int $cpu_quota_pct, int $memory_max_bytes, int $tasks_max): void
    {
        Systemd::writeResourceDropin(self::service($sys_user), $cpu_quota_pct, $memory_max_bytes, $tasks_max);
        // primijeni na živi servis (ako postoji)
        if (Systemd::isActive(self::service($sys_user))) {
            Proc::run(['systemctl', 'restart', self::service($sys_user)]);
        }
    }

    /** Potpuno uklanja dedicirani FPM servis vhosta + sve config artefakte. */
    public static function removePool(string $php_version, string $sys_user): void
    {
        $unit = self::service($sys_user);
        Proc::run(['systemctl', 'disable', '--now', $unit]);
        $unit_path = self::unitPath($sys_user);
        $dropin_dir = $unit_path . '.d';
        if (is_file($unit_path)) {
            unlink($unit_path);
        }
        if (is_dir($dropin_dir)) {
            foreach (glob($dropin_dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            @rmdir($dropin_dir);
        }
        $dir = self::confDir($sys_user);
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            @rmdir($dir);
        }
        @unlink(self::socketPath($sys_user));
        self::removeLegacyPools($sys_user);
        Systemd::daemonReload();
    }

    /** @param array<string, string|null> $backup path => sadržaj (null = bio nepostojeći) */
    private static function restore(array $backup): void
    {
        foreach ($backup as $path => $content) {
            if ($content === null) {
                if (is_file($path)) {
                    unlink($path);
                }
            } else {
                file_put_contents($path, $content);
            }
        }
    }

    /** Ukloni legacy poolove iz dijeljenih php<ver>-fpm mastera i reloadaj ih. */
    private static function removeLegacyPools(string $sys_user): void
    {
        foreach (glob("/etc/php/*/fpm/pool.d/{$sys_user}.conf") ?: [] as $legacy) {
            unlink($legacy);
            if (preg_match('#/etc/php/([0-9.]+)/fpm/#', $legacy, $m)) {
                $shared = "php{$m[1]}-fpm";
                if (Systemd::isActive($shared)) {
                    Proc::run(['systemctl', 'reload', $shared]);
                }
            }
        }
    }

    private static function waitForSocket(string $sys_user, int $tries = 20): bool
    {
        $socket = self::socketPath($sys_user);
        for ($i = 0; $i < $tries; $i++) {
            if (file_exists($socket)) {
                return true;
            }
            usleep(100_000); // 100ms
        }
        return false;
    }

    /** @param array<string, mixed>|null $settings */
    private static function poolIni(string $php_version, string $sys_user, string $vhost_root, ?array $settings): string
    {
        $disable = self::DISABLE_FUNCTIONS;
        if (isset($settings['disable_functions']) && preg_match(self::TUNABLES['disable_functions'], (string) $settings['disable_functions'])) {
            $disable = (string) $settings['disable_functions'];
        }

        $effective = self::DEFAULTS;
        foreach (self::TUNABLES as $key => $rule) {
            if ($key === 'disable_functions' || !isset($settings[$key])) {
                continue;
            }
            $value = trim((string) $settings[$key]);
            if ($value !== '' && preg_match($rule, $value)) {
                $effective[$key] = $value;
            }
        }
        $overrides = '';
        foreach ($effective as $key => $value) {
            $overrides .= "\nphp_admin_value[{$key}] = {$value}";
        }

        $socket = self::socketPath($sys_user);
        $pool = <<<INI
        ; ForgePanel pool — {$sys_user} (dedicirani master)
        [{$sys_user}]
        user = {$sys_user}
        group = {$sys_user}
        listen = {$socket}
        ; owner = nginx (direktni mod), group = www-data (Apache proxy_fcgi mod)
        listen.owner = nginx
        listen.group = www-data
        listen.mode = 0660

        pm = ondemand
        pm.max_children = 10
        pm.process_idle_timeout = 30s
        pm.max_requests = 500

        php_admin_value[open_basedir] = {$vhost_root}:/tmp
        php_admin_value[upload_tmp_dir] = {$vhost_root}/tmp
        php_admin_value[session.save_path] = {$vhost_root}/tmp
        php_admin_value[disable_functions] = {$disable}
        php_admin_value[error_log] = {$vhost_root}/logs/php_error.log
        php_admin_flag[log_errors] = on
        INI;

        return $pool . $overrides . "\n";
    }

    private static function masterConf(string $sys_user, string $vhost_root): string
    {
        $pid = self::RUN_DIR . "/forge-fpm-{$sys_user}.pid";
        $pool = self::confDir($sys_user) . '/pool.conf';
        return <<<CONF
        ; ForgePanel dedicirani FPM master — {$sys_user}
        [global]
        pid = {$pid}
        error_log = {$vhost_root}/logs/fpm.log
        daemonize = no

        include = {$pool}

        CONF;
    }

    private static function unit(string $php_version, string $sys_user): string
    {
        $bin = "/usr/sbin/php-fpm{$php_version}";
        $master = self::confDir($sys_user) . '/php-fpm.conf';
        $svc = self::service($sys_user);
        return <<<UNIT
        [Unit]
        Description=ForgePanel PHP-FPM ({$sys_user}, PHP {$php_version})
        After=network.target
        Documentation=man:php-fpm{$php_version}(8)

        [Service]
        Type=notify
        ExecStart={$bin} --nodaemonize --fpm-config {$master}
        ExecReload=/bin/kill -USR2 \$MAINPID
        Restart=on-failure
        RestartSec=2
        # Hardening (Ubuntu-native): FPM piše samo u vhost dir i /run/php
        PrivateTmp=true
        ProtectSystem=full
        ProtectHome=false
        NoNewPrivileges=true

        [Install]
        WantedBy=multi-user.target
        # forge: unit za {$svc}
        UNIT;
    }
}
