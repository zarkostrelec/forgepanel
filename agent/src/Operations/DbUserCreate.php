<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/** db.user_create — DB user vezan na jednu hostanu bazu, default samo localhost. */
final class DbUserCreate extends Operation
{
    public function validate(array $params): void
    {
        Validator::identifier($params['username'] ?? null, 'username', 32);
        Validator::identifier($params['database'] ?? null, 'database');
        if (!is_string($params['password'] ?? null) || strlen($params['password']) < 12) {
            throw new ValidationException('password mora imati barem 12 znakova');
        }
        if ($params['database'] === 'forgepanel') {
            throw new ValidationException('Panelova baza nije dostupna hostanim userima');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $username = Validator::identifier($params['username'], 'username', 32);
        $database = Validator::identifier($params['database'], 'database');
        $host = ($params['remote_access'] ?? false) === true ? '%' : 'localhost';

        $pdo = $this->db->pdo();
        $quoted_pass = $pdo->quote((string) $params['password']);
        $pdo->exec("CREATE USER IF NOT EXISTS `$username`@`$host` IDENTIFIED BY $quoted_pass");
        $pdo->exec("GRANT ALL PRIVILEGES ON `$database`.* TO `$username`@`$host`");
        $pdo->exec('FLUSH PRIVILEGES');

        return ['username' => $username, 'host' => $host];
    }
}
