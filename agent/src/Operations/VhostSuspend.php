<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** vhost.suspend — preusmjeri vhost na suspend stranicu (i obrnuto). */
final class VhostSuspend extends Operation
{
    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        Validator::phpVersion($params['php_version'] ?? null);
        Validator::oneOf($params['action'] ?? null, ['suspend', 'unsuspend'], 'action');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        $sys_user = 'vh_' . (int) $params['vhost_id'];
        $docroot = Validator::VHOST_ROOT . "/$domain/httpdocs";
        $conf_path = NginxConf::VHOST_CONF_DIR . "/$domain.conf";

        if ($params['action'] === 'suspend') {
            $conf = <<<NGINX
            server {
                listen 80;
                server_name {$domain} www.{$domain};
                return 503;
            }
            NGINX;
            NginxConf::writeAndReload($conf_path, $conf);
        } else {
            NginxConf::writeAndReload(
                $conf_path,
                NginxConf::vhostTemplate($domain, $docroot, Validator::phpVersion($params['php_version']), $sys_user)
            );
        }

        return ['domain' => $domain, 'action' => $params['action']];
    }
}
