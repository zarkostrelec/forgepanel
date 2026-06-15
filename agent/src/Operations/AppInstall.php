<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Apt;
use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * apps.install — marketplace 1-click instalacije:
 *  - nextcloud: PHP, službeni tarball + occ maintenance:install (potpuno spreman)
 *  - ghost:     Node, ghost-cli install + vlastiti systemd unit + nginx proxy
 * Sve se izvršava kao vhost user; DB kreira web sloj prije ovoga.
 */
final class AppInstall extends Operation
{
    private const NEXTCLOUD_TARBALL = 'https://download.nextcloud.com/server/releases/latest.tar.bz2';
    private const GHOST_PORT_BASE = 32000;

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        Validator::oneOf($params['type'] ?? null, ['nextcloud', 'ghost'], 'type');
        Validator::identifier($params['db_name'] ?? null, 'db_name');
        Validator::identifier($params['db_user'] ?? null, 'db_user', 32);
        if (!is_string($params['db_password'] ?? null) || strlen($params['db_password']) < 12) {
            throw new ValidationException('db_password mora imati barem 12 znakova');
        }
        if (($params['type'] ?? '') === 'nextcloud'
            && (!is_string($params['admin_password'] ?? null) || strlen($params['admin_password']) < 10)) {
            throw new ValidationException('admin_password mora imati barem 10 znakova');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        return $params['type'] === 'nextcloud'
            ? $this->installNextcloud($params, $context)
            : $this->installGhost($params, $context);
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function installNextcloud(array $p, TaskContext $context): array
    {
        $vhost_id = (int) $p['vhost_id'];
        $domain = Validator::fqdn($p['domain']);
        $sys_user = 'vh_' . $vhost_id;
        $docroot = Validator::VHOST_ROOT . "/$domain/httpdocs";
        if (!is_dir($docroot)) {
            throw new ValidationException("Docroot ne postoji: $docroot");
        }
        $admin_user = 'admin';

        $context->output('Skidam Nextcloud (latest)');
        $tmp = sys_get_temp_dir() . '/nc-' . bin2hex(random_bytes(6)) . '.tar.bz2';
        Proc::mustRun(['curl', '-fsSL', '-o', $tmp, self::NEXTCLOUD_TARBALL], timeout_s: 600, on_line: $context->output(...));
        $context->progress(40);

        $context->output('Raspakiravam u docroot');
        Proc::mustRun(['tar', '-xjf', $tmp, '-C', $docroot, '--strip-components=1'], timeout_s: 600);
        @unlink($tmp);
        Proc::mustRun(['chown', '-R', "$sys_user:$sys_user", $docroot]);
        $context->progress(70);

        $context->output('occ maintenance:install');
        Proc::mustRun([
            'runuser', '-u', $sys_user, '--', 'php', "$docroot/occ", 'maintenance:install',
            '--database', 'mysql',
            '--database-name', (string) $p['db_name'],
            '--database-user', (string) $p['db_user'],
            '--database-pass', (string) $p['db_password'],
            '--database-host', 'localhost',
            '--admin-user', $admin_user,
            '--admin-pass', (string) $p['admin_password'],
        ], timeout_s: 600, on_line: $context->output(...));

        // Trusted domain — bez ovoga Nextcloud odbija pristup preko domene
        Proc::run(['runuser', '-u', $sys_user, '--', 'php', "$docroot/occ", 'config:system:set', 'trusted_domains', '1', '--value', $domain]);
        Proc::run(['runuser', '-u', $sys_user, '--', 'php', "$docroot/occ", 'config:system:set', 'overwrite.cli.url', '--value', "https://$domain"]);
        $context->progress(95);

        $this->record($vhost_id, 'nextcloud', $domain);
        $this->db->run("UPDATE vhosts SET app_type = 'nextcloud' WHERE id = ?", [$vhost_id]);
        $context->progress(100);
        $context->output("Nextcloud spreman na https://$domain/");
        return ['domain' => $domain, 'admin_url' => "https://$domain/", 'admin_user' => $admin_user];
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function installGhost(array $p, TaskContext $context): array
    {
        $vhost_id = (int) $p['vhost_id'];
        $domain = Validator::fqdn($p['domain']);
        $sys_user = 'vh_' . $vhost_id;
        $app_dir = Validator::VHOST_ROOT . "/$domain/ghost";
        $port = self::GHOST_PORT_BASE + ($vhost_id % 20000);

        $this->ensureNode($context);
        $context->output('Instaliram ghost-cli (global)');
        Proc::mustRun(['npm', 'install', '-g', 'ghost-cli@latest'], timeout_s: 900, on_line: $context->output(...));

        if (!is_dir($app_dir)) {
            mkdir($app_dir, 0o755, true);
        }
        Proc::mustRun(['chown', '-R', "$sys_user:$sys_user", $app_dir]);

        $context->output('ghost install (bez auto-startanja)');
        Proc::mustRun([
            'runuser', '-u', $sys_user, '--', 'ghost', 'install',
            '--db', 'mysql', '--dbhost', 'localhost',
            '--dbuser', (string) $p['db_user'], '--dbpass', (string) $p['db_password'], '--dbname', (string) $p['db_name'],
            '--url', "https://$domain", '--port', (string) $port,
            '--no-prompt', '--no-setup-nginx', '--no-setup-ssl', '--no-setup-mysql',
            '--no-setup-systemd', '--no-start', '--process', 'local', '--dir', $app_dir,
        ], timeout_s: 900, on_line: $context->output(...));
        $context->progress(75);

        // Vlastiti systemd unit (Ghost current/index.js) — kao Node runtime
        $unit = "forgepanel-ghost-$vhost_id";
        $context->output("systemd unit $unit (port $port)");
        file_put_contents("/etc/systemd/system/$unit.service", <<<UNIT
        [Unit]
        Description=ForgePanel Ghost — {$domain}
        After=network.target mysql.service mariadb.service

        [Service]
        Type=simple
        User={$sys_user}
        Group={$sys_user}
        WorkingDirectory={$app_dir}
        Environment=NODE_ENV=production
        ExecStart=/usr/bin/node {$app_dir}/current/index.js
        Restart=always
        RestartSec=4
        MemoryMax=1G
        ProtectSystem=strict
        ReadWritePaths={$app_dir}
        PrivateTmp=true
        NoNewPrivileges=true

        [Install]
        WantedBy=multi-user.target
        UNIT . "\n");
        Systemd::daemonReload();
        Systemd::enableNow($unit);

        $context->output('nginx reverse proxy → Ghost');
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$domain.conf",
            NginxConf::vhostProxyTemplateForPort($domain, $port)
        );

        $this->record($vhost_id, 'ghost', $domain);
        $this->db->run("UPDATE vhosts SET status = 'active', app_type = 'ghost' WHERE id = ?", [$vhost_id]);
        $context->progress(100);
        $context->output("Ghost spreman na https://$domain/ghost/ (dovrši kreiranje admina)");
        return ['domain' => $domain, 'admin_url' => "https://$domain/ghost/", 'unit' => $unit, 'port' => $port];
    }

    private function ensureNode(TaskContext $context): void
    {
        if (Proc::run(['which', 'node'])->ok()) {
            return;
        }
        $context->output('Instaliram Node.js');
        Apt::install(['nodejs', 'npm'], $context->output(...));
    }

    private function record(int $vhost_id, string $type, string $domain): void
    {
        $this->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ["app_instance_{$type}_$vhost_id", json_encode(['domain' => $domain, 'type' => $type, 'installed_at' => date('c')])]
        );
    }
}
