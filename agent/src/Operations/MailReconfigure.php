<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\MailConf;
use ForgePanel\Agent\TaskContext;

/**
 * mail.reconfigure — ponovno zapiši config već instaliranog mail stacka (bez apta).
 * Deterministički prepiše Postfix mape, Dovecot SQL auth (uklj. gašenje default PAM/system
 * auth koji zasjenjuje SQL) i Rspamd iz panel baze, pa restarta servise. Popravlja
 * driftani ili neispravan config bez rušenja/reinstalacije mail stacka.
 */
final class MailReconfigure extends Operation
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
        (new MailConf($this->config, $this->db))->reconfigure($context->output(...));
        $context->progress(100);
        $context->output('Mail stack rekonfiguriran (Postfix + Dovecot + Rspamd).');
        return ['reconfigured' => true];
    }
}
