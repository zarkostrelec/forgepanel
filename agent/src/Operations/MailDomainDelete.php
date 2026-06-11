<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\MailConf;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** mail.domain_delete — briše DKIM ključ i maildir podatke domene. */
final class MailDomainDelete extends Operation
{
    public function validate(array $params): void
    {
        Validator::fqdn($params['domain'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        (new MailConf($this->config, $this->db))->domainRemoveDkim($domain);
        $maildir = MailConf::VMAIL_DIR . '/' . $domain;
        if (is_dir($maildir)) {
            Proc::mustRun(['rm', '-rf', '--one-file-system', $maildir]);
        }
        return ['deleted' => $domain];
    }
}
