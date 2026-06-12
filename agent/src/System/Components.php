<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Registar komponenti koje update orkestrator upravlja: paketi, servis,
 * config direktoriji (za snapshot/rollback) i config test (health check).
 */
final class Components
{
    /** @var array<string, array{packages: list<string>, service: ?string, config_dirs: list<string>, config_test: ?list<string>}> */
    public const CATALOG = [
        'nginx' => [
            'packages' => ['nginx'],
            'service' => 'nginx',
            'config_dirs' => ['/etc/nginx'],
            'config_test' => ['nginx', '-t'],
        ],
        'apache2' => [
            'packages' => ['apache2', 'apache2-bin', 'apache2-data'],
            'service' => 'apache2',
            'config_dirs' => ['/etc/apache2'],
            'config_test' => ['apachectl', 'configtest'],
        ],
        'mariadb' => [
            'packages' => ['mariadb-server', 'mariadb-client'],
            'service' => 'mariadb',
            'config_dirs' => ['/etc/mysql'],
            'config_test' => null,
        ],
        'php8.1' => [
            'packages' => ['php8.1-cli', 'php8.1-fpm', 'php8.1-mysql', 'php8.1-curl', 'php8.1-mbstring', 'php8.1-xml', 'php8.1-zip', 'php8.1-intl'],
            'service' => 'php8.1-fpm',
            'config_dirs' => ['/etc/php/8.1'],
            'config_test' => ['php-fpm8.1', '-t'],
        ],
        'php8.2' => [
            'packages' => ['php8.2-cli', 'php8.2-fpm', 'php8.2-mysql', 'php8.2-curl', 'php8.2-mbstring', 'php8.2-xml', 'php8.2-zip', 'php8.2-intl'],
            'service' => 'php8.2-fpm',
            'config_dirs' => ['/etc/php/8.2'],
            'config_test' => ['php-fpm8.2', '-t'],
        ],
        'php8.3' => [
            'packages' => ['php8.3-cli', 'php8.3-fpm', 'php8.3-mysql', 'php8.3-curl', 'php8.3-mbstring', 'php8.3-xml', 'php8.3-zip', 'php8.3-intl'],
            'service' => 'php8.3-fpm',
            'config_dirs' => ['/etc/php/8.3'],
            'config_test' => ['php-fpm8.3', '-t'],
        ],
        'php8.4' => [
            'packages' => ['php8.4-cli', 'php8.4-fpm', 'php8.4-mysql', 'php8.4-curl', 'php8.4-mbstring', 'php8.4-xml', 'php8.4-zip', 'php8.4-intl'],
            'service' => 'php8.4-fpm',
            'config_dirs' => ['/etc/php/8.4'],
            'config_test' => ['php-fpm8.4', '-t'],
        ],
        // 8.5 dolazi iz distro archiva (26.04 universe) — jedina verzija dostupna
        // dok ondrej PPA ne objavi resolute suite
        'php8.5' => [
            'packages' => ['php8.5-cli', 'php8.5-fpm', 'php8.5-mysql', 'php8.5-curl', 'php8.5-mbstring', 'php8.5-xml', 'php8.5-zip', 'php8.5-intl'],
            'service' => 'php8.5-fpm',
            'config_dirs' => ['/etc/php/8.5'],
            'config_test' => ['php-fpm8.5', '-t'],
        ],
        'postfix' => [
            'packages' => ['postfix', 'postfix-mysql'],
            'service' => 'postfix',
            'config_dirs' => ['/etc/postfix'],
            'config_test' => ['postfix', 'check'],
        ],
        'dovecot' => [
            'packages' => ['dovecot-core', 'dovecot-imapd', 'dovecot-pop3d', 'dovecot-lmtpd', 'dovecot-mysql'],
            'service' => 'dovecot',
            'config_dirs' => ['/etc/dovecot'],
            'config_test' => ['doveconf', '-n'],
        ],
        'rspamd' => [
            'packages' => ['rspamd'],
            'service' => 'rspamd',
            'config_dirs' => ['/etc/rspamd'],
            'config_test' => ['rspamadm', 'configtest'],
        ],
        'bind9' => [
            'packages' => ['bind9', 'bind9utils'],
            'service' => 'named',
            'config_dirs' => ['/etc/bind'],
            'config_test' => ['named-checkconf'],
        ],
        'proftpd' => [
            'packages' => ['proftpd-core', 'proftpd-basic'],
            'service' => 'proftpd',
            'config_dirs' => ['/etc/proftpd'],
            'config_test' => ['proftpd', '-t'],
        ],
        'fail2ban' => [
            'packages' => ['fail2ban'],
            'service' => 'fail2ban',
            'config_dirs' => ['/etc/fail2ban'],
            'config_test' => null,
        ],
    ];

    /** Komponenta kojoj paket pripada, ili null. */
    public static function componentForPackage(string $package): ?string
    {
        foreach (self::CATALOG as $name => $def) {
            if (in_array($package, $def['packages'], true)) {
                return $name;
            }
        }
        // ondrej PHP ima desetke php8.X-* ekstenzija — sve pripadaju php8.X komponenti
        if (preg_match('/^php(8\.\d)-/', $package, $m) && isset(self::CATALOG['php' . $m[1]])) {
            return 'php' . $m[1];
        }
        return null;
    }

    /** Instalirani paketi komponente (preskače ne-instalirane). @return list<string> */
    public static function installedPackages(string $component): array
    {
        $installed = [];
        foreach (self::CATALOG[$component]['packages'] ?? [] as $package) {
            if (Apt::installedVersion($package) !== null) {
                $installed[] = $package;
            }
        }
        return $installed;
    }

    /** Verzija komponente = verzija prvog instaliranog paketa. */
    public static function currentVersion(string $component): ?string
    {
        foreach (self::CATALOG[$component]['packages'] ?? [] as $package) {
            $version = Apt::installedVersion($package);
            if ($version !== null) {
                return $version;
            }
        }
        return null;
    }
}
