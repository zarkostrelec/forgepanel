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
    // Dugotrajno (claude zna trajati desetke sekundi) → task queue, NE sinkroni
    // socket poziv. Inače bi blokirao agentovu glavnu petlju i web FPM worker.
    public function isLongRunning(): bool
    {
        return true;
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
        // Stream chunkova u task.output kako stižu → UI prikazuje odgovor live.
        // (Ako claude bufferira, on_line svejedno dobije sve odjednom na kraju.)
        $answer = (new ClaudeCli($this->config))->query(
            (string) $params['prompt'],
            150,
            fn (string $chunk) => $context->outputRaw($chunk),
        );
        return ['answer' => $answer];
    }
}
