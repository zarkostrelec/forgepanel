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
 * python.app — Python WSGI/ASGI aplikacija kao systemd user-servis per vhost
 * (venv + gunicorn) + nginx reverse proxy. Ekvivalent Node runtimea za Python.
 */
final class PythonApp extends Operation
{
    private const PORT_BASE = 31000;

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
            // WSGI/ASGI callable, npr. "app:app" ili "myproj.wsgi:application"
            $entry = $params['entry'] ?? 'app:app';
            if (!is_string($entry) || !preg_match('#^[a-zA-Z0-9_.]{1,80}:[a-zA-Z0-9_]{1,40}$#', $entry)) {
                throw new ValidationException('entry mora biti modul:callable (npr. app:app)');
            }
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);
        $action = (string) $params['action'];
        $sys_user = 'vh_' . $vhost_id;
        $unit = "forgepanel-python-$vhost_id";

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
        $this->ensurePython($context);
        $app_dir = Validator::VHOST_ROOT . "/$domain/pyapp";
        if (!is_dir($app_dir)) {
            mkdir($app_dir, 0o755, true);
            Proc::mustRun(['chown', "$sys_user:$sys_user", $app_dir]);
        }
        $venv = "$app_dir/.venv";
        $port = self::PORT_BASE + ($vhost_id % 20000);
        $entry = (string) ($params['entry'] ?? 'app:app');
        $asgi = (bool) ($params['asgi'] ?? false);

        $context->output('Kreiram venv + gunicorn (kao vhost user)');
        if (!is_dir($venv)) {
            Proc::mustRun(['runuser', '-u', $sys_user, '--', 'python3', '-m', 'venv', $venv], timeout_s: 300, on_line: $context->output(...));
        }
        $pip = "$venv/bin/pip";
        Proc::mustRun(['runuser', '-u', $sys_user, '--', $pip, 'install', '--upgrade', 'pip', 'gunicorn'], timeout_s: 600, on_line: $context->output(...));
        if (is_file("$app_dir/requirements.txt")) {
            $context->output('pip install -r requirements.txt');
            Proc::run(['runuser', '-u', $sys_user, '--', $pip, 'install', '-r', "$app_dir/requirements.txt"], timeout_s: 900, on_line: $context->output(...));
        }
        if ($asgi) {
            Proc::run(['runuser', '-u', $sys_user, '--', $pip, 'install', 'uvicorn'], timeout_s: 300, on_line: $context->output(...));
        }

        $context->output("systemd unit $unit (port $port)");
        $exec = $asgi
            ? "$venv/bin/gunicorn -k uvicorn.workers.UvicornWorker -b 127.0.0.1:$port $entry"
            : "$venv/bin/gunicorn -b 127.0.0.1:$port $entry";
        file_put_contents("/etc/systemd/system/$unit.service", <<<UNIT
        [Unit]
        Description=ForgePanel Python app — {$domain}
        After=network.target

        [Service]
        Type=simple
        User={$sys_user}
        Group={$sys_user}
        WorkingDirectory={$app_dir}
        Environment=PORT={$port}
        Environment=PYTHONUNBUFFERED=1
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

        $context->output('nginx reverse proxy → Python app');
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$domain.conf",
            NginxConf::vhostProxyTemplateForPort($domain, $port)
        );

        $this->db->run("UPDATE vhosts SET status = 'active', app_type = 'python' WHERE id = ?", [$vhost_id]);
        return ['unit' => $unit, 'port' => $port, 'active' => Systemd::isActive($unit)];
    }

    private function ensurePython(TaskContext $context): void
    {
        if (Proc::run(['which', 'python3'])->ok() && is_file('/usr/bin/python3')) {
            // venv modul mora postojati
            if (Proc::run(['python3', '-c', 'import venv'])->ok()) {
                return;
            }
        }
        $context->output('Instaliram Python 3 + venv');
        Apt::install(['python3', 'python3-venv', 'python3-pip'], $context->output(...));
    }
}
