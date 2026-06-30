<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Fs;
use ForgePanel\Agent\System\PhpFpm;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/**
 * vhost.reapply_isolation — backfill izolacije na POSTOJEĆI vhost (stari model):
 * migrira pool s dijeljenog php<ver>-fpm mastera na dedicirani per-vhost FPM servis,
 * (re)postavi cgroup kvote plana (CPUQuota/MemoryMax/TasksMax) i disk project quotu.
 *
 * Idempotentno: na već migriranom vhostu samo prepiše config + limite. writePool
 * radi php-fpm -t + rollback + čeka socket, pa neuspjeh ne ostavlja vhost bez FPM-a.
 */
final class VhostReapplyIsolation extends Operation
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
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);
        $php = Validator::phpVersion($params['php_version']);
        $sys_user = 'vh_' . $vhost_id;
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;
        $settings = is_array($params['settings'] ?? null) ? $params['settings'] : [];

        $context->output("Migriram $domain na dedicirani FPM servis ($sys_user)");
        PhpFpm::writePool($php, $sys_user, $vhost_root, $settings);
        $context->progress(50);

        $limited = false;
        if (isset($params['cpu_quota_pct'], $params['memory_max_bytes'], $params['tasks_max'])) {
            $context->output('Per-vhost cgroup kvote (CPUQuota/MemoryMax/TasksMax)');
            PhpFpm::setLimits(
                $sys_user,
                (int) $params['cpu_quota_pct'],
                (int) $params['memory_max_bytes'],
                (int) $params['tasks_max'],
            );
            $limited = true;
        }
        $context->progress(80);

        $quota = false;
        if (isset($params['disk_bytes']) && (int) $params['disk_bytes'] > 0) {
            $quota = Fs::setProjectQuota($vhost_root, (int) $params['disk_bytes'], $vhost_id);
            $context->output($quota
                ? 'Disk project quota postavljena (' . (int) $params['disk_bytes'] . ' B)'
                : 'Disk project quota preskočena (fs bez prjquota?) — best-effort');
        }

        $context->progress(100);
        $context->output("Izolacija primijenjena na $domain.");

        return ['domain' => $domain, 'limited' => $limited, 'disk_quota' => $quota];
    }
}
