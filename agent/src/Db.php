<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

final class Db
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO(
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
        return $this->pdo;
    }

    /** @param list<mixed> $params */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
