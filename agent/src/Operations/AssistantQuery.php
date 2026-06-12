<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\ClaudeCli;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;

/**
 * assistant.query — dijagnostički upit lokalnom Claude CLI-ju (read-only).
 * Claude SAMO čita i predlaže; predložene komande izvršava admin potvrdom
 * (op assistant.exec). Nikad ne mijenja sustav sam.
 */
final class AssistantQuery extends Operation
{
    public function isLongRunning(): bool
    {
        return false;
    }

    public function validate(array $params): void
    {
        $prompt = $params['prompt'] ?? null;
        if (!is_string($prompt) || trim($prompt) === '' || mb_strlen($prompt) > 24000) {
            throw new ValidationException('prompt mora biti string (1–24000 znakova)');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $answer = (new ClaudeCli($this->config))->query((string) $params['prompt']);
        return ['answer' => $answer];
    }
}
