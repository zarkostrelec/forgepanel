<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * db.user_update — promjena lozinke i/ili remote pristupa. Pošto su MySQL useri
 * vezani uz host, mijenjanje localhost↔'%' znači drop pa recreate na ciljanom
 * hostu; isti put pokriva i promjenu lozinke (uvijek dobivamo punu lozinku).
 */
final class DbUserUpdate extends Operation
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
        // drop na oba hosta pa recreate na ciljanom — pokriva i lozinku i remote toggle
        $pdo->exec("DROP USER IF EXISTS `$username`@`localhost`");
        $pdo->exec("DROP USER IF EXISTS `$username`@`%`");
        $pdo->exec("CREATE USER `$username`@`$host` IDENTIFIED BY $quoted_pass");
        $pdo->exec("GRANT ALL PRIVILEGES ON `$database`.* TO `$username`@`$host`");
        $pdo->exec('FLUSH PRIVILEGES');

        return ['username' => $username, 'host' => $host];
    }
}
