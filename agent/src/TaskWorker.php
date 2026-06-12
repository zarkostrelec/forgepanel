<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

/**
 * Obrađuje task queue (tablica tasks): dugotrajne operacije se izvršavaju
 * asinkrono, progress i output se zapisuju u red taska pa ih UI prati kroz SSE.
 */
final class TaskWorker
{
    public function __construct(
        private readonly Db $db,
        private readonly OperationRegistry $registry,
        private readonly AuditLogger $audit,
    ) {
    }

    /** Serijski (1 task → bez dpkg utrka), ali u djetetu da glavna petlja ostane živa. */
    private const MAX_CONCURRENT = 1;

    /** @var array<int, int> pid => task_id */
    private array $children = [];
    private bool $recovered = false;

    public function tick(): void
    {
        $this->recoverOrphans();
        $this->reap();
        if (count($this->children) >= self::MAX_CONCURRENT) {
            return;
        }
        $task = $this->claimNext();
        if ($task === null) {
            return;
        }

        // Forkamo: dugotrajni task (apt install, AI upit) izvršava dijete, a
        // agentova glavna petlja ostaje responzivna (socket, scheduler, watchdog).
        $pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
        if ($pid === -1) {
            $this->process($task); // fallback: sinkrono (degradirano)
            return;
        }
        if ($pid === 0) {
            // DIJETE: svjež DB handle (PDO se ne smije dijeliti preko forka)
            $this->db->reconnect();
            try {
                $this->process($task);
            } catch (\Throwable) {
                // process() već bilježi failed; spriječi propagaciju
            }
            exit(0);
        }
        // RODITELJ: napusti dijeljenu konekciju i prati dijete
        $this->db->reconnect();
        $this->children[$pid] = (int) $task['id'];
    }

    /** Pokupi završenu djecu; crash/kill prije finish() → failed. */
    private function reap(): void
    {
        foreach ($this->children as $pid => $task_id) {
            $res = pcntl_waitpid($pid, $status, WNOHANG);
            if ($res === 0) {
                continue;
            }
            unset($this->children[$pid]);
            $clean = $res > 0 && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
            if (!$clean) {
                $this->db->run(
                    "UPDATE tasks SET status = 'failed', error = COALESCE(error, 'task proces prekinut'),
                     finished_at = NOW() WHERE id = ? AND status = 'running'",
                    [$task_id]
                );
            }
        }
    }

    /** Nakon (re)starta agenta: 'running' taskovi nemaju živo dijete → failed. */
    private function recoverOrphans(): void
    {
        if ($this->recovered) {
            return;
        }
        $this->recovered = true;
        $this->db->run(
            "UPDATE tasks SET status = 'failed', error = 'agent restartan tijekom izvršavanja',
             finished_at = NOW() WHERE status = 'running'"
        );
    }

    /** @return array<string, mixed>|null */
    private function claimNext(): ?array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $task = $this->db->run(
                "SELECT * FROM tasks WHERE status = 'pending' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
            )->fetch();
            if ($task === false) {
                $pdo->rollBack();
                return null;
            }
            $this->db->run(
                "UPDATE tasks SET status = 'running', started_at = NOW() WHERE id = ?",
                [$task['id']]
            );
            $pdo->commit();
            return $task;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $task */
    private function process(array $task): void
    {
        $task_id = (int) $task['id'];
        $op_code = (string) $task['op'];

        $operation = $this->registry->resolve($op_code);
        if ($operation === null) {
            $this->finish($task_id, 'failed', error: 'unknown_operation');
            $this->audit->log('agent', 'task.rejected', ['task_id' => $task_id, 'op' => $op_code]);
            return;
        }

        $context = new TaskContext(
            on_progress: fn (int $p) => $this->db->run('UPDATE tasks SET progress = ? WHERE id = ?', [$p, $task_id]),
            on_output: fn (string $line) => $this->db->run(
                "UPDATE tasks SET output = CONCAT(COALESCE(output, ''), ?) WHERE id = ?",
                [$line, $task_id]
            ),
        );

        try {
            $params = json_decode((string) $task['params'], true, 16, JSON_THROW_ON_ERROR);
            $operation->validate($params);
            $operation->execute($params, $context);
            $this->finish($task_id, 'done');
            $this->audit->log('agent', 'task.done', ['task_id' => $task_id, 'op' => $op_code]);
        } catch (\Throwable $e) {
            $this->finish($task_id, 'failed', error: $e->getMessage());
            if ($op_code === 'vhost.create' && isset($params['vhost_id'])) {
                $this->db->run("UPDATE vhosts SET status = 'error' WHERE id = ?", [(int) $params['vhost_id']]);
            }
            $this->audit->log('agent', 'task.failed', [
                'task_id' => $task_id,
                'op' => $op_code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function finish(int $task_id, string $status, ?string $error = null): void
    {
        $this->db->run(
            'UPDATE tasks SET status = ?, error = ?, progress = IF(? = \'done\', 100, progress),
             finished_at = NOW() WHERE id = ?',
            [$status, $error, $status, $task_id]
        );
    }
}
