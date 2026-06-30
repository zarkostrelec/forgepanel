<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\System\PhpFpm;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** vhost.php_set — promjena PHP verzije: novi pool, prebaci nginx upstream, makni stari pool. */
final class VhostPhpSet extends Operation
{
    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        Validator::phpVersion($params['old_version'] ?? null);
        Validator::phpVersion($params['new_version'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        $sys_user = 'vh_' . (int) $params['vhost_id'];
        $old = Validator::phpVersion($params['old_version']);
        $new = Validator::phpVersion($params['new_version']);
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;

        $row = $this->db->one('SELECT web_backend, php_settings FROM vhosts WHERE id = ?', [(int) $params['vhost_id']]);
        $backend = (string) ($row['web_backend'] ?? 'nginx');
        // promjena verzije zadržava per-domena PHP override
        $settings = is_array($row) ? (json_decode((string) ($row['php_settings'] ?? ''), true) ?: []) : [];

        // Dedicirani FPM servis je per-vhost (NEovisan o PHP verziji): writePool samo
        // prepiše master conf na novu verziju i restarta isti servis. Stari pool ne
        // postoji zasebno (removePool bi srušio upravo ovaj servis) — zato ga ne zovemo.
        // $old se zadržava radi API kompatibilnosti taska.
        unset($old);
        PhpFpm::writePool($new, $sys_user, $vhost_root, $settings);
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$domain.conf",
            NginxConf::templateFor($backend, $domain, $vhost_root . '/httpdocs', $new, $sys_user)
        );

        return ['domain' => $domain, 'php_version' => $new];
    }
}
