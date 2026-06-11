<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * node.app — Node.js aplikacija kao systemd user-servis per vhost +
 * nginx reverse proxy. Ekvivalent cPanel Application Managera, jednostavniji.
 */
final class NodeApp extends Operation
{
    private const PORT_BASE = 30000;

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        Validator::oneOf($params['action'] ?? null, ['create', 'start', 'stop', 'restart', 'remove'], 'action');
        if (($params['action'] ?? '') === 'create') {
            // entry point relativan, npm start ili node <file>
            $entry = $params['entry'] ?? 'index.js';
            if (!is_string($entry) || !preg_match('#^[a-zA-Z0-9_./-]{1,128}$#', $entry) || str_contains($entry, '..')) {
                throw new ValidationException('entry nije ispravan');
            }
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);
        $action = (string) $params['action'];
        $sys_user = 'vh_' . $vhost_id;
        $unit = "forgepanel-node-$vhost_id";

        if ($action === 'remove') {
            Proc::run(['systemctl', 'disable', '--now', $unit]);
            @unlink("/etc/systemd/system/$unit.service");
            Systemd::daemonReload();
            return ['removed' => $unit];
        }
        if (in_array($action, ['start', 'stop', 'restart'], true)) {
            Proc::mustRun(['systemctl', $action, $unit]);
            return ['unit' => $unit, 'action' => $action];
        }

        // create
        $this->ensureNode($context);
        $app_dir = Validator::VHOST_ROOT . "/$domain/nodeapp";
        if (!is_dir($app_dir)) {
            mkdir($app_dir, 0o755, true);
            Proc::mustRun(['chown', "$sys_user:$sys_user", $app_dir]);
        }
        $port = self::PORT_BASE + ($vhost_id % 20000);
        $entry = (string) ($params['entry'] ?? 'index.js');

        // npm install ako ima package.json
        if (is_file("$app_dir/package.json")) {
            $context->output('npm install (kao vhost user)');
            Proc::run(['runuser', '-u', $sys_user, '--', 'npm', '--prefix', $app_dir, 'install', '--omit=dev'], timeout_s: 900, on_line: $context->output(...));
        }

        $context->output("systemd unit $unit (port $port)");
        $exec = str_ends_with($entry, '.js') ? "/usr/bin/node $app_dir/$entry" : "/usr/bin/npm --prefix $app_dir start";
        file_put_contents("/etc/systemd/system/$unit.service", <<<UNIT
        [Unit]
        Description=ForgePanel Node app — {$domain}
        After=network.target

        [Service]
        Type=simple
        User={$sys_user}
        Group={$sys_user}
        WorkingDirectory={$app_dir}
        Environment=PORT={$port}
        Environment=NODE_ENV=production
        ExecStart={$exec}
        Restart=always
        RestartSec=3
        MemoryMax=512M
        ProtectSystem=strict
        ReadWritePaths={$app_dir}
        PrivateTmp=true
        NoNewPrivileges=true

        [Install]
        WantedBy=multi-user.target
        UNIT . "\n");
        Systemd::daemonReload();
        Systemd::enableNow($unit);

        $context->output('nginx reverse proxy → Node app');
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$domain.conf",
            NginxConf::vhostProxyTemplateForPort($domain, $port)
        );

        $this->db->run("UPDATE vhosts SET status = 'active' WHERE id = ?", [$vhost_id]);
        return ['unit' => $unit, 'port' => $port, 'active' => Systemd::isActive($unit)];
    }

    private function ensureNode(TaskContext $context): void
    {
        if (Proc::run(['which', 'node'])->ok()) {
            return;
        }
        $context->output('Instaliram Node.js (NodeSource)');
        // NodeSource repo se dodaje kroz installer; ovdje fallback na Ubuntu paket
        \ForgePanel\Agent\System\Apt::install(['nodejs', 'npm'], $context->output(...));
    }
}
