<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Fs;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

final class FsDelete extends Operation
{
    public function validate(array $params): void
    {
        Validator::vhostPath($params['path'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        Fs::delete((string) $params['path']);
        return ['deleted' => $params['path']];
    }
}
