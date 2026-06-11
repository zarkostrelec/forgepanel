<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\MailConf;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** mail.domain_add — DKIM ključ za domenu; mailboxi ne trebaju ništa (DB mape). */
final class MailDomainAdd extends Operation
{
    public function validate(array $params): void
    {
        Validator::fqdn($params['domain'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        return (new MailConf($this->config, $this->db))->domainAddDkim((string) $params['domain']);
    }
}
