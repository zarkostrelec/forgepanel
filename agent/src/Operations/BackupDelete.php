<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;

final class BackupDelete extends Operation
{
    public function validate(array $params): void
    {
        BackupRestore::backupPath($params['path'] ?? null);
    }

    public function execute(array $params, TaskContext $context): array
    {
        $backup_dir = BackupRestore::backupPath($params['path']);
        Proc::mustRun(['rm', '-rf', '--one-file-system', $backup_dir]);
        return ['deleted' => $backup_dir];
    }
}
