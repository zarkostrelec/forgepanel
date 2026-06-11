<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

final class DbDelete extends Operation
{
    public function validate(array $params): void
    {
        $name = Validator::identifier($params['name'] ?? null, 'name');
        if (in_array($name, ['forgepanel', 'mysql', 'information_schema', 'performance_schema', 'sys'], true)) {
            throw new \ForgePanel\Agent\ValidationException('Sistemska baza se ne može brisati');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $name = Validator::identifier($params['name'], 'name');
        $this->db->pdo()->exec("DROP DATABASE IF EXISTS `$name`");
        return ['deleted' => $name];
    }
}
