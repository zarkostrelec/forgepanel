<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** db.create — kreira hostanu bazu. Identifikatori su validirani pa je quoting siguran. */
final class DbCreate extends Operation
{
    public function validate(array $params): void
    {
        Validator::identifier($params['name'] ?? null, 'name');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $name = Validator::identifier($params['name'], 'name');
        $this->db->pdo()->exec(
            "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        return ['name' => $name];
    }
}
