<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Apt;
use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/**
 * mail.webmail_setup — Roundcube webmail na zasebnom vhostu (spec: modul mail).
 * Ubuntu universe paketi (roundcube-core + roundcube-mysql), vlastita baza,
 * vlastiti FPM pool (www-data, izoliran od panela i hostanih stranica),
 * nginx vhost s privremenim self-signed certom — AutoSSL (ssl.issue task) izdaje pravi.
 */
final class WebmailSetup extends Operation
{
    private const RC_ROOT = '/var/lib/roundcube';
    private const POOL_CONF = '/etc/php/8.4/fpm/pool.d/forgepanel-webmail.conf';

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::fqdn($params['hostname'] ?? null, 'hostname');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $hostname = Validator::fqdn($params['hostname'], 'hostname');

        $context->output("Instaliram Roundcube pakete (universe)\n");
        $context->progress(10);
        // dbconfig preskačemo — bazu i config pišemo sami, deterministički
        Proc::mustRun(
            ['debconf-set-selections'],
            stdin: "roundcube-core roundcube/dbconfig-install boolean false\n"
        );
        Apt::install(['roundcube-core', 'roundcube-mysql', 'php8.4-fpm'], $context->output(...));

        $context->output("Roundcube baza + DB user\n");
        $context->progress(40);
        $rc_pass = bin2hex(random_bytes(24));
        $pdo = $this->db->pdo();
        $quoted = $pdo->quote($rc_pass);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec("CREATE USER IF NOT EXISTS 'roundcube'@'localhost' IDENTIFIED BY $quoted");
        $pdo->exec("ALTER USER 'roundcube'@'localhost' IDENTIFIED BY $quoted");
        $pdo->exec("GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'localhost'");
        $pdo->exec('FLUSH PRIVILEGES');

        // Inicijalna shema samo ako je baza prazna (idempotentnost / repair)
        $tables = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'roundcube'")->fetchColumn();
        if ((int) $tables === 0) {
            $schema = '/usr/share/dbconfig-common/data/roundcube/install/mysql';
            if (!is_file($schema)) {
                $schema = '/usr/share/roundcube/SQL/mysql.initial.sql';
            }
            // agent = root → MariaDB/MySQL auth_socket, bez lozinke
            Proc::mustRun(['mysql', 'roundcube'], stdin: (string) file_get_contents($schema));
        }

        $context->output("Roundcube konfiguracija (IMAP/SMTP localhost)\n");
        $context->progress(60);
        $des_key = bin2hex(random_bytes(12)); // točno 24 znaka
        $config = <<<PHP
        <?php
        /* ForgePanel — Roundcube konfiguracija (NE uređivati ručno, panel je prepisuje) */
        \$config['db_dsnw'] = 'mysql://roundcube:{$rc_pass}@localhost/roundcube';
        \$config['imap_host'] = 'localhost:143';
        \$config['smtp_host'] = 'localhost:587';
        \$config['smtp_user'] = '%u';
        \$config['smtp_pass'] = '%p';
        \$config['des_key'] = '{$des_key}';
        \$config['product_name'] = 'Webmail';
        \$config['support_url'] = '';
        \$config['plugins'] = ['archive', 'zipdownload', 'managesieve'];
        \$config['managesieve_host'] = 'localhost:4190';
        \$config['enable_installer'] = false;
        \$config['language'] = 'hr_HR';
        PHP;
        file_put_contents('/etc/roundcube/config.inc.php.new', $config . "\n");
        Proc::mustRun(['chgrp', 'www-data', '/etc/roundcube/config.inc.php.new']);
        chmod('/etc/roundcube/config.inc.php.new', 0o640);
        rename('/etc/roundcube/config.inc.php.new', '/etc/roundcube/config.inc.php');

        $context->output("FPM pool za webmail (www-data, izoliran)\n");
        $context->progress(70);
        $pool = <<<INI
        ; ForgePanel — webmail (Roundcube) pool
        [forgepanel-webmail]
        user = www-data
        group = www-data
        listen = /run/php/fpm-webmail.sock
        listen.owner = www-data
        listen.group = www-data
        pm = ondemand
        pm.max_children = 8
        pm.process_idle_timeout = 30s
        php_admin_value[open_basedir] = /var/lib/roundcube:/usr/share/roundcube:/etc/roundcube:/var/log/roundcube:/tmp
        php_admin_value[upload_max_filesize] = 25M
        php_admin_value[post_max_size] = 26M
        php_admin_flag[expose_php] = off
        INI;
        file_put_contents(self::POOL_CONF, $pool . "\n");
        Proc::mustRun(['php-fpm8.4', '-t']);
        Systemd::reload('php8.4-fpm');

        $context->output("nginx vhost {$hostname} (self-signed do AutoSSL-a)\n");
        $context->progress(85);
        $ssl_dir = "/etc/forgepanel/ssl/$hostname";
        if (!is_file("$ssl_dir/fullchain.pem")) {
            mkdir($ssl_dir, 0o700, true);
            Proc::mustRun(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                '-keyout', "$ssl_dir/privkey.pem", '-out', "$ssl_dir/fullchain.pem",
                '-days', '30', '-subj', "/CN=$hostname", '-addext', "subjectAltName=DNS:$hostname"]);
        }
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$hostname.conf",
            self::nginxTemplate($hostname)
        );

        $this->db->run(
            "INSERT INTO settings (`key`, value) VALUES ('webmail', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [json_encode(['hostname' => $hostname, 'status' => 'installed', 'installed_at' => date('Y-m-d H:i:s')])]
        );
        $this->db->run(
            "INSERT INTO components (name, status, packages) VALUES ('roundcube', 'installed', ?)
             ON DUPLICATE KEY UPDATE status = 'installed'",
            ['["roundcube-core","roundcube-mysql"]']
        );

        $context->progress(100);
        $context->output("Webmail spreman: https://$hostname (cert izdaje AutoSSL task)\n");
        return ['hostname' => $hostname];
    }

    public static function nginxTemplate(string $hostname): string
    {
        $root = self::RC_ROOT;
        return <<<NGINX
        # ForgePanel — webmail (Roundcube) na zasebnom vhostu
        server {
            listen 80;
            server_name {$hostname};

            location /.well-known/acme-challenge/ {
                root /var/www/forgepanel-acme;
            }
            location / {
                return 301 https://\$host\$request_uri;
            }
        }

        server {
            listen 443 ssl;
            http2 on;
            server_name {$hostname};

            ssl_certificate     /etc/forgepanel/ssl/{$hostname}/fullchain.pem;
            ssl_certificate_key /etc/forgepanel/ssl/{$hostname}/privkey.pem;

            include /etc/nginx/forgepanel/snippets/security.conf;

            root {$root};
            index index.php;
            client_max_body_size 26m;

            location /.well-known/acme-challenge/ {
                root /var/www/forgepanel-acme;
            }
            # Roundcube interni direktoriji nisu javni
            location ~ ^/(config|temp|logs|SQL|bin)/ { deny all; }
            location ~ /\\. { deny all; }

            location / {
                try_files \$uri \$uri/ /index.php\$is_args\$args;
            }
            location ~ \\.php\$ {
                include fastcgi_params;
                fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
                fastcgi_pass unix:/run/php/fpm-webmail.sock;
            }
        }
        NGINX;
    }
}
