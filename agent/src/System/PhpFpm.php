<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Per-vhost PHP-FPM poolovi: ondemand, vlastiti sistemski user, open_basedir,
 * disable_functions preset, vlastiti tmp — puna izolacija vhosta.
 */
final class PhpFpm
{
    private const DISABLE_FUNCTIONS = 'exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,pcntl_fork,dl';

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

    public static function poolPath(string $php_version, string $sys_user): string
    {
        return "/etc/php/{$php_version}/fpm/pool.d/{$sys_user}.conf";
    }

    /** @param array<string, mixed>|null $settings per-domena PHP override */
    public static function writePool(string $php_version, string $sys_user, string $vhost_root, ?array $settings = null): void
    {
        // disable_functions: korisnik smije zamijeniti default ako pošalje vlastiti
        $disable = self::DISABLE_FUNCTIONS;
        if (isset($settings['disable_functions']) && preg_match(self::TUNABLES['disable_functions'], (string) $settings['disable_functions'])) {
            $disable = (string) $settings['disable_functions'];
        }

        // optimizirani defaulti + korisnički override (validacija formata sprječava INI injection)
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

        $pool = <<<INI
        ; ForgePanel pool — {$sys_user}
        [{$sys_user}]
        user = {$sys_user}
        group = {$sys_user}
        listen = /run/php/fpm-{$sys_user}.sock
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

        $conf_path = self::poolPath($php_version, $sys_user);
        $backup = is_file($conf_path) ? file_get_contents($conf_path) : null;
        file_put_contents($conf_path, $pool . $overrides . "\n");

        $unit = "php{$php_version}-fpm";
        $test = Proc::run(["php-fpm{$php_version}", '-t']);
        if (!$test->ok()) {
            $backup !== null ? file_put_contents($conf_path, $backup) : unlink($conf_path);
            throw new \RuntimeException("php-fpm{$php_version} -t pao, config vraćen: " . trim($test->stderr));
        }
        Systemd::reload($unit);
    }

    public static function removePool(string $php_version, string $sys_user): void
    {
        $conf_path = self::poolPath($php_version, $sys_user);
        if (is_file($conf_path)) {
            unlink($conf_path);
            Systemd::reload("php{$php_version}-fpm");
        }
    }
}
