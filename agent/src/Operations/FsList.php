<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Fs;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

final class FsList extends Operation
{
    public function validate(array $params): void
    {
        Validator::vhostPath($params['path'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        return ['entries' => Fs::listDir((string) $params['path'])];
    }
}
