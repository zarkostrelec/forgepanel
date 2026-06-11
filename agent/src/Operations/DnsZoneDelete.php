<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\BindConf;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

final class DnsZoneDelete extends Operation
{
    public function validate(array $params): void
    {
        Validator::fqdn($params['domain'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        BindConf::deleteZone((string) $params['domain']);
        return ['deleted' => $params['domain']];
    }
}
