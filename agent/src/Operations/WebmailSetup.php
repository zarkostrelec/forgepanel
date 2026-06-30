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

    // Webmail vozi panelov PHP (agent radi na njemu, pa je runtime verzija = panelova) —
    // na 26.04 bez ondrej PPA to je distro 8.5, s ondrejem 8.4
    private const PANEL_PHP = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    private const POOL_CONF = '/etc/php/' . self::PANEL_PHP . '/fpm/pool.d/forgepanel-webmail.conf';

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
        Apt::install(['roundcube-core', 'roundcube-mysql', 'php' . self::PANEL_PHP . '-fpm'], $context->output(...));

        // PHP 8.5 je uveo native array_first(); pakirani Roundcube (26.04 universe)
        // ga redeklarira bez zaštite → "Cannot redeclare function array_first" (HTTP 500).
        // Zamotaj deklaraciju u function_exists guard (idempotentno).
        $this->patchRoundcubePhp85($context);

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
        /* IMAP/SMTP preko TLS-a na loopbacku: Dovecot/Postfix ne dopuštaju plaintext
           auth na NEšifriranoj vezi (disable_plaintext_auth=yes) → prijava bi padala
           ("Login failed") s ispravnom lozinkom. Cert je izdan za panel FQDN (ne za
           'localhost'), pa na loopbacku isključujemo provjeru peera. */
        \$config['imap_host'] = 'ssl://localhost:993';
        \$config['imap_conn_options'] = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
        \$config['smtp_host'] = 'ssl://localhost:465';
        \$config['smtp_user'] = '%u';
        \$config['smtp_pass'] = '%p';
        \$config['smtp_conn_options'] = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
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
        ; socket vlasnik = nginx (panelov web user) da se izbjegne 502 (EACCES);
        ; FPM worker ostaje www-data (vlasnik Roundcube fileova)
        listen.owner = nginx
        listen.group = www-data
        listen.mode = 0660
        pm = ondemand
        pm.max_children = 8
        pm.process_idle_timeout = 30s
        php_admin_value[open_basedir] = /var/lib/roundcube:/usr/share/roundcube:/etc/roundcube:/var/log/roundcube:/tmp
        php_admin_value[upload_max_filesize] = 25M
        php_admin_value[post_max_size] = 26M
        php_admin_flag[expose_php] = off
        INI;
        file_put_contents(self::POOL_CONF, $pool . "\n");
        Proc::mustRun(['php-fpm' . self::PANEL_PHP, '-t']);
        Systemd::reload('php' . self::PANEL_PHP . '-fpm');

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

    /**
     * PHP 8.5 native array_first() vs. Roundcubeova nezaštićena deklaracija → 500.
     * Zamota deklaraciju u if (!function_exists(...)) {...}. Idempotentno; tiho preskače
     * ako je već zakrpano ili ako se predložak ne podudara (novi paket bez problema).
     */
    private function patchRoundcubePhp85(TaskContext $context): void
    {
        $file = '/usr/share/roundcube/program/lib/Roundcube/bootstrap.php';
        if (!is_file($file)) {
            return;
        }
        $src = (string) file_get_contents($file);
        if (str_contains($src, "function_exists('array_first')")) {
            return;
        }
        $patched = preg_replace(
            '/(function array_first\(\$array\)\n\{\n.*?\n\}\n)/s',
            "if (!function_exists('array_first')) {\n\$1}\n",
            $src,
            1,
            $count
        );
        if (is_string($patched) && $count === 1) {
            file_put_contents($file, $patched);
            $context->output("Roundcube bootstrap.php zakrpan za PHP 8.5 (array_first guard)\n");
        }
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
