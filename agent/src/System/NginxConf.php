<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Pisanje nginx konfiguracija s obaveznim `nginx -t` prije reloada
 * i automatskim rollbackom na zadnju ispravnu verziju ako test padne.
 */
final class NginxConf
{
    public const VHOST_CONF_DIR = '/etc/nginx/forgepanel/vhosts';

    public static function writeAndReload(string $conf_path, string $content): void
    {
        $dir = dirname($conf_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $backup = null;
        if (is_file($conf_path)) {
            $backup = file_get_contents($conf_path);
        }

        file_put_contents($conf_path, $content);

        $test = Proc::run(['nginx', '-t']);
        if (!$test->ok()) {
            // Rollback na prethodnu ispravnu verziju
            if ($backup !== null) {
                file_put_contents($conf_path, $backup);
            } else {
                unlink($conf_path);
            }
            throw new \RuntimeException('nginx -t pao, config vraćen: ' . trim($test->stderr));
        }

        Systemd::reload('nginx');
        ConfigGit::snapshot('agent', 'nginx: ' . basename($conf_path));
    }

    public static function remove(string $conf_path): void
    {
        if (is_file($conf_path)) {
            unlink($conf_path);
            $test = Proc::run(['nginx', '-t']);
            if ($test->ok()) {
                Systemd::reload('nginx');
            }
        }
    }

    /** IPv6 listen direktive samo ako sustav ima IPv6. */
    private static function listenV6(int $port, string $extra = ''): string
    {
        $has_v6 = is_readable('/proc/net/if_inet6') && trim((string) file_get_contents('/proc/net/if_inet6')) !== '';
        return $has_v6 ? "listen [::]:{$port}{$extra};" : '';
    }

    /** Bira template prema web backendu vhosta. */
    public static function templateFor(string $backend, string $domain, string $docroot, string $php_version, string $sys_user): string
    {
        return $backend === 'nginx_apache'
            ? self::vhostProxyTemplate($domain, $docroot)
            : self::vhostTemplate($domain, $docroot, $php_version, $sys_user);
    }

    /** nginx ispred Apachea — SSL/statika/ACME na nginxu, ostalo proxy na 127.0.0.1:7080. */
    public static function vhostProxyTemplate(string $domain, string $docroot): string
    {
        $v6_80 = self::listenV6(80);
        $v6_443 = self::listenV6(443, ' ssl');
        return <<<NGINX
        # ForgePanel vhost — {$domain} (nginx → Apache reverse proxy, .htaccess mod)
        server {
            listen 80;
            {$v6_80}
            server_name {$domain} www.{$domain};

            location /.well-known/acme-challenge/ {
                root /var/www/forgepanel-acme;
            }
            location / {
                return 301 https://\$host\$request_uri;
            }
        }

        server {
            listen 443 ssl;
            {$v6_443}
            http2 on;
            server_name {$domain} www.{$domain};

            ssl_certificate     /etc/forgepanel/ssl/{$domain}/fullchain.pem;
            ssl_certificate_key /etc/forgepanel/ssl/{$domain}/privkey.pem;

            access_log /var/www/vhosts/{$domain}/logs/access.log;
            error_log  /var/www/vhosts/{$domain}/logs/error.log;

            include /etc/nginx/forgepanel/snippets/security.conf;

            location /.well-known/acme-challenge/ {
                root /var/www/forgepanel-acme;
            }

            location / {
                proxy_pass http://127.0.0.1:7080;
                proxy_set_header Host \$host;
                proxy_set_header X-Real-IP \$remote_addr;
                proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
                proxy_set_header X-Forwarded-Proto https;
                proxy_read_timeout 300;
            }
        }
        NGINX;
    }

    /**
     * Redirect vhost — domena samo radi {$code} redirect na postojeću stranicu ($target),
     * čuvajući path i query. Bez docroota/FPM-a. ACME challenge i dalje radi (AutoSSL).
     */
    public static function vhostRedirectTemplate(string $domain, string $target, int $code): string
    {
        $v6_80 = self::listenV6(80);
        $v6_443 = self::listenV6(443, ' ssl');
        return <<<NGINX
        # ForgePanel vhost — {$domain} (redirect {$code} → {$target})
        server {
            listen 80;
            {$v6_80}
            server_name {$domain} www.{$domain};
            location /.well-known/acme-challenge/ { root /var/www/forgepanel-acme; }
            location / { return {$code} https://{$target}\$request_uri; }
        }
        server {
            listen 443 ssl;
            {$v6_443}
            http2 on;
            server_name {$domain} www.{$domain};
            ssl_certificate     /etc/forgepanel/ssl/{$domain}/fullchain.pem;
            ssl_certificate_key /etc/forgepanel/ssl/{$domain}/privkey.pem;
            location /.well-known/acme-challenge/ { root /var/www/forgepanel-acme; }
            location / { return {$code} https://{$target}\$request_uri; }
        }
        NGINX;
    }

    /** nginx → reverse proxy na lokalni port (Docker container ili Node app). */
    public static function vhostProxyTemplateForPort(string $domain, int $port): string
    {
        $v6_80 = self::listenV6(80);
        $v6_443 = self::listenV6(443, ' ssl');
        return <<<NGINX
        # ForgePanel vhost — {$domain} (nginx → 127.0.0.1:{$port})
        server {
            listen 80;
            {$v6_80}
            server_name {$domain} www.{$domain};
            location /.well-known/acme-challenge/ { root /var/www/forgepanel-acme; }
            location / { return 301 https://\$host\$request_uri; }
        }
        server {
            listen 443 ssl;
            {$v6_443}
            http2 on;
            server_name {$domain} www.{$domain};
            ssl_certificate     /etc/forgepanel/ssl/{$domain}/fullchain.pem;
            ssl_certificate_key /etc/forgepanel/ssl/{$domain}/privkey.pem;
            access_log /var/www/vhosts/{$domain}/logs/access.log;
            location / {
                proxy_pass http://127.0.0.1:{$port};
                proxy_set_header Host \$host;
                proxy_set_header X-Real-IP \$remote_addr;
                proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
                proxy_set_header X-Forwarded-Proto https;
                proxy_http_version 1.1;
                proxy_set_header Upgrade \$http_upgrade;
                proxy_set_header Connection "upgrade";
            }
        }
        NGINX;
    }

    public static function vhostTemplate(string $domain, string $docroot, string $php_version, string $sys_user): string
    {        $v6_80 = self::listenV6(80);
        $v6_443 = self::listenV6(443, ' ssl');
        return <<<NGINX
        # ForgePanel vhost — generirano, ručne izmjene idu kroz panel (custom direktive)
        server {
            listen 80;
            {$v6_80}
            server_name {$domain} www.{$domain};

            location /.well-known/acme-challenge/ {
                root /var/www/forgepanel-acme;
            }
            location / {
                return 301 https://\$host\$request_uri;
            }
        }

        server {
            listen 443 ssl;
            {$v6_443}
            http2 on;
            server_name {$domain} www.{$domain};

            root {$docroot};
            index index.php index.html;

            ssl_certificate     /etc/forgepanel/ssl/{$domain}/fullchain.pem;
            ssl_certificate_key /etc/forgepanel/ssl/{$domain}/privkey.pem;

            access_log /var/www/vhosts/{$domain}/logs/access.log;
            error_log  /var/www/vhosts/{$domain}/logs/error.log;

            include /etc/nginx/forgepanel/snippets/security.conf;

            location / {
                try_files \$uri \$uri/ /index.php?\$query_string;
            }

            location ~ \.php$ {
                try_files \$uri =404;
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
                fastcgi_pass unix:/run/php/fpm-{$sys_user}.sock;
            }

            location ~ /\.(?!well-known) {
                deny all;
            }
        }
        NGINX;
    }

    /** Legacy PHP (Docker FPM) — nginx servira statiku, PHP ide na 127.0.0.1:{port} containera. */
    public static function vhostLegacyTemplate(string $domain, string $docroot, int $port): string
    {
        $v6_80 = self::listenV6(80);
        $v6_443 = self::listenV6(443, ' ssl');
        return <<<NGINX
        # ForgePanel vhost — {$domain} (Legacy PHP preko Dockera → 127.0.0.1:{$port})
        server {
            listen 80;
            {$v6_80}
            server_name {$domain} www.{$domain};

            location /.well-known/acme-challenge/ {
                root /var/www/forgepanel-acme;
            }
            location / {
                return 301 https://\$host\$request_uri;
            }
        }

        server {
            listen 443 ssl;
            {$v6_443}
            http2 on;
            server_name {$domain} www.{$domain};

            root {$docroot};
            index index.php index.html;

            ssl_certificate     /etc/forgepanel/ssl/{$domain}/fullchain.pem;
            ssl_certificate_key /etc/forgepanel/ssl/{$domain}/privkey.pem;

            access_log /var/www/vhosts/{$domain}/logs/access.log;
            error_log  /var/www/vhosts/{$domain}/logs/error.log;

            include /etc/nginx/forgepanel/snippets/security.conf;

            location / {
                try_files \$uri \$uri/ /index.php?\$query_string;
            }

            location ~ \.php$ {
                try_files \$uri =404;
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
                fastcgi_pass 127.0.0.1:{$port};
                fastcgi_read_timeout 300;
            }

            location ~ /\.(?!well-known) {
                deny all;
            }
        }
        NGINX;
    }
}
