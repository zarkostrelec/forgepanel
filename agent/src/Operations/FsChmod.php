<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Fs;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

final class FsChmod extends Operation
{
    public function validate(array $params): void
    {
        Validator::vhostPath($params['path'] ?? null);
        if (!is_string($params['mode'] ?? null) || !preg_match('/^[0-7]{3,4}$/', $params['mode'])) {
            throw new ValidationException('mode mora biti oktalni string, npr. "0644"');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        Fs::chmod((string) $params['path'], (int) octdec((string) $params['mode']));
        return ['path' => $params['path'], 'mode' => $params['mode']];
    }
}
