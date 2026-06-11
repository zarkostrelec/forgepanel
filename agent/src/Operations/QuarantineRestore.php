<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/** quarantine.action — vrati file iz karantene na originalnu lokaciju, ili ga trajno obriši. */
final class QuarantineRestore extends Operation
{
    public function validate(array $params): void
    {
        Validator::positiveInt($params['item_id'] ?? null, 'item_id');
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::vhostPath($params['original_path'] ?? null, 'original_path');
        Validator::oneOf($params['action'] ?? null, ['restore', 'delete'], 'action');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $item_id = (int) $params['item_id'];
        $vhost_id = (int) $params['vhost_id'];
        $original_path = Validator::vhostPath($params['original_path'], 'original_path');
        $quarantine_path = MalwareScan::QUARANTINE_DIR . "/{$vhost_id}_{$item_id}_" . basename($original_path);

        if (!is_file($quarantine_path)) {
            throw new ValidationException('File nije u karanteni');
        }

        if ($params['action'] === 'restore') {
            $dir = dirname($original_path);
            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
            rename($quarantine_path, $original_path);
            Proc::run(['chown', 'vh_' . $vhost_id . ':vh_' . $vhost_id, $original_path]);
        } else {
            unlink($quarantine_path);
        }

        return ['item_id' => $item_id, 'action' => $params['action']];
    }
}
