<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\System\PhpFpm;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

final class VhostDelete extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        Validator::phpVersion($params['php_version'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        $sys_user = 'vh_' . (int) $params['vhost_id'];
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;

        $context->output('Uklanjam nginx config');
        NginxConf::remove(NginxConf::VHOST_CONF_DIR . "/$domain.conf");
        \ForgePanel\Agent\System\ApacheConf::removeVhost($domain);
        \ForgePanel\Agent\System\LegacyPhp::down((int) $params['vhost_id'], $context->output(...));
        @unlink("/etc/cron.d/forgepanel-{$sys_user}");
        $context->progress(25);

        $context->output('Uklanjam FPM pool');
        PhpFpm::removePool(Validator::phpVersion($params['php_version']), $sys_user);
        $context->progress(50);

        $context->output("Brišem fileove ($vhost_root)");
        if (is_dir($vhost_root)) {
            Validator::vhostPath($vhost_root);
            Proc::mustRun(['rm', '-rf', '--one-file-system', $vhost_root]);
        }
        $context->progress(80);

        $context->output("Uklanjam usera $sys_user");
        if (Proc::run(['id', $sys_user])->ok()) {
            Proc::mustRun(['userdel', $sys_user]);
        }
        $context->progress(100);

        return ['deleted' => $domain];
    }
}
