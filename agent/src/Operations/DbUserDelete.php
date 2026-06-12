<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** db.user_delete — briše MySQL usera s oba moguća hosta (localhost i '%'). */
final class DbUserDelete extends Operation
{
    public function validate(array $params): void
    {
        Validator::identifier($params['username'] ?? null, 'username', 32);
    }

    public function execute(array $params, TaskContext $context): array
    {
        $username = Validator::identifier($params['username'], 'username', 32);
        $pdo = $this->db->pdo();
        $pdo->exec("DROP USER IF EXISTS `$username`@`localhost`");
        $pdo->exec("DROP USER IF EXISTS `$username`@`%`");
        $pdo->exec('FLUSH PRIVILEGES');

        return ['deleted' => $username];
    }
}
