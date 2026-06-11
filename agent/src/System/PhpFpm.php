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

    public static function poolPath(string $php_version, string $sys_user): string
    {
        return "/etc/php/{$php_version}/fpm/pool.d/{$sys_user}.conf";
    }

    public static function writePool(string $php_version, string $sys_user, string $vhost_root): void
    {
        $pool = <<<INI
        ; ForgePanel pool — {$sys_user}
        [{$sys_user}]
        user = {$sys_user}
        group = {$sys_user}
        listen = /run/php/fpm-{$sys_user}.sock
        listen.owner = nginx
        listen.group = nginx
        listen.mode = 0660

        pm = ondemand
        pm.max_children = 10
        pm.process_idle_timeout = 30s
        pm.max_requests = 500

        php_admin_value[open_basedir] = {$vhost_root}:/tmp
        php_admin_value[upload_tmp_dir] = {$vhost_root}/tmp
        php_admin_value[session.save_path] = {$vhost_root}/tmp
        php_admin_value[disable_functions] = %s
        php_admin_value[error_log] = {$vhost_root}/logs/php_error.log
        php_admin_flag[log_errors] = on
        INI;

        $conf_path = self::poolPath($php_version, $sys_user);
        $backup = is_file($conf_path) ? file_get_contents($conf_path) : null;
        file_put_contents($conf_path, sprintf($pool, self::DISABLE_FUNCTIONS) . "\n");

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
