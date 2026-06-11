<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Apache iza nginxa (reverse proxy) — per-domena izbor za .htaccess/WordPress.
 * Apache sluša samo na 127.0.0.1:7080; nginx ostaje ispred za SSL/HTTP3/statiku.
 */
final class ApacheConf
{
    public const SITES_DIR = '/etc/apache2/sites-available';
    public const PROXY_PORT = 7080;

    public static function ensureInstalled(?\Closure $log = null): void
    {
        if (!is_dir('/etc/apache2')) {
            $log?->__invoke("Instaliram Apache (prvi vhost s nginx_apache backendom)\n");
            Apt::install(['apache2'], $log);
        }
        if (is_file('/etc/apache2/forgepanel-configured')) {
            return;
        }

        // Apache isključivo lokalno iza nginxa
        file_put_contents('/etc/apache2/ports.conf', 'Listen 127.0.0.1:' . self::PROXY_PORT . "\n");
        foreach (['proxy_fcgi', 'rewrite', 'headers', 'remoteip', 'setenvif'] as $mod) {
            Proc::mustRun(['a2enmod', '-q', $mod]);
        }
        Proc::run(['a2dissite', '000-default']);

        // Pravi IP posjetitelja iz nginx proxyja (fail2ban i logovi vide stvarne IP-ove)
        file_put_contents(
            '/etc/apache2/conf-available/forgepanel-remoteip.conf',
            "RemoteIPHeader X-Real-IP\nRemoteIPInternalProxy 127.0.0.1\n"
        );
        Proc::mustRun(['a2enconf', '-q', 'forgepanel-remoteip']);

        Proc::mustRun(['apachectl', 'configtest']);
        Systemd::enableNow('apache2');
        touch('/etc/apache2/forgepanel-configured');
    }

    public static function writeVhost(string $domain, string $docroot, string $sys_user, string $vhost_root): void
    {
        $conf_path = self::SITES_DIR . "/forgepanel-{$domain}.conf";
        $backup = is_file($conf_path) ? file_get_contents($conf_path) : null;

        $conf = <<<APACHE
        # ForgePanel Apache vhost — {$domain} (iza nginx proxyja)
        <VirtualHost 127.0.0.1:7080>
            ServerName {$domain}
            ServerAlias www.{$domain}
            DocumentRoot {$docroot}

            <Directory {$docroot}>
                AllowOverride All
                Require all granted
                Options -Indexes +FollowSymLinks
            </Directory>

            <FilesMatch \\.php$>
                SetHandler "proxy:unix:/run/php/fpm-{$sys_user}.sock|fcgi://localhost"
            </FilesMatch>

            ErrorLog {$vhost_root}/logs/apache_error.log
        </VirtualHost>
        APACHE;

        file_put_contents($conf_path, $conf . "\n");
        Proc::mustRun(['a2ensite', '-q', "forgepanel-{$domain}"]);

        $test = Proc::run(['apachectl', 'configtest']);
        if (!$test->ok()) {
            // Rollback na prethodnu ispravnu verziju
            Proc::run(['a2dissite', '-q', "forgepanel-{$domain}"]);
            $backup !== null ? file_put_contents($conf_path, $backup) : @unlink($conf_path);
            throw new \RuntimeException('apachectl configtest pao, config vraćen: ' . trim($test->stderr));
        }
        Systemd::reload('apache2');
    }

    public static function removeVhost(string $domain): void
    {
        $conf_path = self::SITES_DIR . "/forgepanel-{$domain}.conf";
        if (!is_file($conf_path)) {
            return;
        }
        Proc::run(['a2dissite', '-q', "forgepanel-{$domain}"]);
        unlink($conf_path);
        if (Proc::run(['apachectl', 'configtest'])->ok() && Systemd::isActive('apache2')) {
            Systemd::reload('apache2');
        }
    }
}
