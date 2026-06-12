<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;

/**
 * assistant.exec — izvršava komandu KOJU JE ADMIN IZRIČITO POTVRDIO u panelu
 * (Forge AI "predloži → potvrdi → izvrši"). Ovo je namjerna, čovjekom potvrđena
 * iznimka od pravila "agent ne prima raw komande": komanda je prethodno
 * prikazana adminu i pokreće se tek na njegov klik. Web sloj je gat-a na
 * admin rolu + audit_log; socket je root:fpanel 0660.
 */
final class AssistantExec extends Operation
{
    public function isLongRunning(): bool
    {
        return false;
    }

    public function validate(array $params): void
    {
        $cmd = $params['command'] ?? null;
        if (!is_string($cmd) || trim($cmd) === '' || mb_strlen($cmd) > 4000) {
            throw new ValidationException('command mora biti string (1–4000 znakova)');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $cmd = (string) $params['command'];
        $result = Proc::run(['bash', '-lc', $cmd], timeout_s: 120);
        return [
            'exit' => $result->exit_code,
            'stdout' => mb_substr($result->stdout, 0, 100000),
            'stderr' => mb_substr($result->stderr, 0, 20000),
        ];
    }
}
