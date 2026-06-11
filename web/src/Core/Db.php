<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

final class Db
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): \PDO
    {
        return $this->pdo ??= new \PDO(
            $this->config->get('db_dsn'),
            $this->config->get('db_user'),
            $this->config->get('db_pass'),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    /** @param list<mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param list<mixed> $params @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function lastId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }
}
