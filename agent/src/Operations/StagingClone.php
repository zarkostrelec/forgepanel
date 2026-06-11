<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\System\PhpFpm;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/**
 * staging.clone — universal staging: klonira BILO KOJU PHP stranicu
 * (ne samo WP kao Plesk): fileovi + baza na staging subdomenu, zaseban
 * FPM pool, automatski search-replace URL-ova u dumpu.
 */
final class StagingClone extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['source_vhost_id'] ?? null, 'source_vhost_id');
        Validator::positiveInt($params['staging_vhost_id'] ?? null, 'staging_vhost_id');
        Validator::fqdn($params['source_domain'] ?? null, 'source_domain');
        Validator::fqdn($params['staging_domain'] ?? null, 'staging_domain');
        foreach ($params['databases'] ?? [] as $pair) {
            Validator::identifier($pair['source'] ?? null, 'source_db');
            Validator::identifier($pair['staging'] ?? null, 'staging_db');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $source_domain = Validator::fqdn($params['source_domain'], 'source_domain');
        $staging_domain = Validator::fqdn($params['staging_domain'], 'staging_domain');
        $staging_id = (int) $params['staging_vhost_id'];
        $staging_user = 'vh_' . $staging_id;
        $php_version = Validator::phpVersion($params['php_version'] ?? '8.4');

        $source_root = Validator::VHOST_ROOT . "/$source_domain";
        $staging_root = Validator::VHOST_ROOT . "/$staging_domain";

        $context->output("Sistemski user $staging_user + direktoriji");
        if (!Proc::run(['id', $staging_user])->ok()) {
            Proc::mustRun(['useradd', '--system', '--shell', '/usr/sbin/nologin',
                '--home-dir', $staging_root, '--no-create-home', $staging_user]);
        }
        foreach (['', '/httpdocs', '/logs', '/tmp', '/private'] as $sub) {
            if (!is_dir($staging_root . $sub)) {
                mkdir($staging_root . $sub, 0o755, true);
            }
        }
        $context->progress(15);

        $context->output("Kopiram fileove ($source_domain → $staging_domain)");
        Proc::mustRun(['rsync', '-a', '--delete', '--exclude', 'tmp/',
            "$source_root/httpdocs/", "$staging_root/httpdocs/"], timeout_s: 1800);
        $context->progress(45);

        // Baze: dump source, search-replace domene, import u staging bazu
        foreach ($params['databases'] ?? [] as $i => $pair) {
            $source_db = Validator::identifier($pair['source'], 'source_db');
            $staging_db = Validator::identifier($pair['staging'], 'staging_db');
            $context->output("Kloniram bazu $source_db → $staging_db (search-replace URL-ova)");

            $this->db->pdo()->exec("CREATE DATABASE IF NOT EXISTS `$staging_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $dump = Proc::mustRun(['mariadb-dump', '--single-transaction', $source_db], timeout_s: 1800)->stdout;
            // search-replace domene (jedan prolaz — pokriva i //domena i golu domenu)
            // radi za WP i generičke PHP appove
            $replaced = str_replace($source_domain, $staging_domain, $dump);
            Proc::mustRun(['mariadb', $staging_db], stdin: $replaced, timeout_s: 1800);
            $context->progress(45 + (int) (25 * ($i + 1) / max(1, count($params['databases']))));
        }

        $context->output('FPM pool + nginx config za staging');
        Proc::mustRun(['chown', '-R', "$staging_user:$staging_user", $staging_root]);
        PhpFpm::writePool($php_version, $staging_user, $staging_root);

        // Self-signed dok AutoSSL ne izda pravi
        $ssl_dir = "/etc/forgepanel/ssl/$staging_domain";
        if (!is_file("$ssl_dir/fullchain.pem")) {
            mkdir($ssl_dir, 0o700, true);
            Proc::mustRun(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                '-keyout', "$ssl_dir/privkey.pem", '-out', "$ssl_dir/fullchain.pem",
                '-days', '7', '-subj', "/CN=$staging_domain"]);
        }
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$staging_domain.conf",
            NginxConf::vhostTemplate($staging_domain, "$staging_root/httpdocs", $php_version, $staging_user)
        );

        $this->db->run("UPDATE vhosts SET status = 'active' WHERE id = ?", [$staging_id]);
        $this->db->run(
            'INSERT INTO staging_envs (source_vhost_id, staging_vhost_id, last_sync) VALUES (?, ?, NOW())',
            [(int) $params['source_vhost_id'], $staging_id]
        );
        $context->progress(100);
        $context->output("Staging $staging_domain spreman.");

        return ['staging_domain' => $staging_domain];
    }
}
