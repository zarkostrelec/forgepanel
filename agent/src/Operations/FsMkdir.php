<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Fs;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

final class FsMkdir extends Operation
{
    public function validate(array $params): void
    {
        Validator::vhostPath($params['path'] ?? null);
        Validator::identifier($params['owner'] ?? null, 'owner');
    }

    public function execute(array $params, TaskContext $context): array
    {
        Fs::mkdir((string) $params['path'], (string) $params['owner']);
        return ['created' => $params['path']];
    }
}
