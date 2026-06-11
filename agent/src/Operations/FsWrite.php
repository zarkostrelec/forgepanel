<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Fs;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

final class FsWrite extends Operation
{
    public function validate(array $params): void
    {
        Validator::vhostPath($params['path'] ?? null);
        Validator::identifier($params['owner'] ?? null, 'owner');
        if (!is_string($params['content_b64'] ?? null) || base64_decode($params['content_b64'], true) === false) {
            throw new ValidationException('content_b64 nije ispravan base64');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        Fs::write(
            (string) $params['path'],
            (string) base64_decode((string) $params['content_b64'], true),
            (string) $params['owner']
        );
        return ['written' => $params['path']];
    }
}
