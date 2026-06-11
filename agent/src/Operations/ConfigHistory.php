<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\ConfigGit;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** config.history — povijest, diff i restore config time-machinea. */
final class ConfigHistory extends Operation
{
    public function validate(array $params): void
    {
        Validator::oneOf($params['action'] ?? null, ['snapshot', 'list', 'diff', 'restore'], 'action');
        if (in_array($params['action'] ?? null, ['diff', 'restore'], true)
            && !preg_match('/^[0-9a-f]{7,40}$/', (string) ($params['hash'] ?? ''))
        ) {
            throw new \ForgePanel\Agent\ValidationException('hash je obavezan');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $by = (string) ($params['changed_by'] ?? 'agent');
        return match ($params['action']) {
            'snapshot' => ['hash' => ConfigGit::snapshot($by, (string) ($params['message'] ?? 'snapshot'))],
            'list' => ['history' => ConfigGit::history()],
            'diff' => ['diff' => ConfigGit::diff((string) $params['hash'])],
            'restore' => $this->restore((string) $params['hash'], $by),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function restore(string $hash, string $by): array
    {
        ConfigGit::restore($hash, $by);
        return ['restored' => $hash];
    }
}
