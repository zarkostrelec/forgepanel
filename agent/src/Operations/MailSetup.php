<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\MailConf;
use ForgePanel\Agent\TaskContext;

/** mail.setup — jednokratna instalacija i konfiguracija cijelog mail stacka. */
final class MailSetup extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
    }

    public function execute(array $params, TaskContext $context): array
    {
        $context->progress(5);
        (new MailConf($this->config, $this->db))->setup($context->output(...));
        $context->progress(100);
        $context->output('Mail stack spreman (Postfix + Dovecot + Rspamd).');
        return ['installed' => true];
    }
}
