<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\BindConf;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/** dns.zone_write — (re)generira kompletnu zonu iz panelovih zapisa. */
final class DnsZoneWrite extends Operation
{
    public function validate(array $params): void
    {
        Validator::fqdn($params['domain'] ?? null);
        Validator::positiveInt($params['serial'] ?? null, 'serial');
        Validator::fqdn($params['ns1'] ?? null, 'ns1');
        Validator::fqdn($params['ns2'] ?? null, 'ns2');
        if (!is_array($params['records'] ?? null)) {
            throw new ValidationException('records mora biti lista');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        BindConf::writeZone(
            (string) $params['domain'],
            $params['records'],
            (int) $params['serial'],
            Validator::fqdn($params['ns1'], 'ns1'),
            Validator::fqdn($params['ns2'], 'ns2'),
        );
        return ['domain' => $params['domain'], 'serial' => $params['serial']];
    }
}
