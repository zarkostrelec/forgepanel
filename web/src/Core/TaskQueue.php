<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/** Dugotrajne operacije: web sloj ih stavlja u tablicu tasks, agent ih obrađuje asinkrono. */
final class TaskQueue
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed> $params */
    public function enqueue(string $op, array $params, ?int $user_id): int
    {
        $this->db->run(
            'INSERT INTO tasks (op, params, user_id) VALUES (?, ?, ?)',
            [$op, json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $user_id]
        );
        return $this->db->lastId();
    }

    /** @return array<string, mixed>|null */
    public function get(int $task_id): ?array
    {
        return $this->db->one('SELECT * FROM tasks WHERE id = ?', [$task_id]);
    }
}
