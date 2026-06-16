<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * db.grantees — read-only: vraća MySQL/MariaDB korisnike koji imaju schema-level
 * privilegije na zadanim bazama. Služi panelu za REKONCILIJACIJU (uvoz postojećih
 * DB usera koji nisu zabilježeni u panelu). NE mijenja ništa, NE dira lozinke.
 */
final class DbGrantees extends Operation
{
    /** Sistemski/servisni accounti koje ne uvozimo kao hostane DB usere. */
    private const SKIP = ['forgepanel', 'root', 'mysql', 'mariadb.sys', 'mysql.sys', 'mysql.session', 'mysql.infoschema', 'debian-sys-maint', 'healthcheck'];

    public function validate(array $params): void
    {
        if (!is_array($params['databases'] ?? null)) {
            throw new ValidationException('databases mora biti lista naziva baza');
        }
        foreach ($params['databases'] as $d) {
            Validator::identifier($d, 'database');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT DISTINCT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES WHERE TABLE_SCHEMA = ?'
        );
        $out = [];
        foreach ($params['databases'] as $database) {
            $database = Validator::identifier($database, 'database');
            $stmt->execute([$database]);
            $users = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $grantee) {
                // GRANTEE oblik: 'user'@'host'
                if (!preg_match("/^'(.*)'@'(.*)'$/", (string) $grantee, $m)) {
                    continue;
                }
                if (in_array($m[1], self::SKIP, true)) {
                    continue;
                }
                $users[] = ['user' => $m[1], 'host' => $m[2]];
            }
            $out[$database] = $users;
        }
        return $out;
    }
}
