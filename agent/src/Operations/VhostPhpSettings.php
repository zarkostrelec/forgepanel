<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\PhpFpm;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** vhost.php_settings — per-domena PHP override (memory_limit, upload_max_filesize, ...) u FPM pool. */
final class VhostPhpSettings extends Operation
{
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
        $php = Validator::phpVersion($params['php_version']);
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;
        $settings = is_array($params['settings'] ?? null) ? $params['settings'] : [];

        // PhpFpm::writePool sam validira format svake vrijednosti + php-fpm -t s rollbackom
        PhpFpm::writePool($php, $sys_user, $vhost_root, $settings);
        return ['domain' => $domain, 'applied' => count($settings)];
    }
}
