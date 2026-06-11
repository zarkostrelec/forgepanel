<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/** Server-Sent Events — log streaming, task progress, monitoring. Bez WebSocket servera. */
final class Sse
{
    public static function start(): void
    {
        ignore_user_abort(false);
        set_time_limit(0);
        Response::securityHeaders();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    public static function send(string $event, mixed $data): void
    {
        echo "event: $event\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        if (connection_aborted()) {
            exit;
        }
    }

    /** Streama task progress dok ne završi (UI task tray). */
    public static function streamTask(TaskQueue $tasks, int $task_id, int $max_seconds = 1800): never
    {
        self::start();
        $last_state = '';
        $deadline = time() + $max_seconds;

        while (time() < $deadline) {
            $task = $tasks->get($task_id);
            if ($task === null) {
                self::send('error', ['error' => 'task_not_found']);
                exit;
            }
            $state = $task['status'] . '|' . $task['progress'] . '|' . strlen((string) $task['output']);
            if ($state !== $last_state) {
                self::send('progress', [
                    'task_id' => $task_id,
                    'status' => $task['status'],
                    'progress' => (int) $task['progress'],
                    'output' => $task['output'],
                    'error' => $task['error'],
                ]);
                $last_state = $state;
            }
            if (in_array($task['status'], ['done', 'failed', 'cancelled'], true)) {
                self::send('end', ['status' => $task['status']]);
                exit;
            }
            usleep(500_000);
        }
        self::send('end', ['status' => 'stream_timeout']);
        exit;
    }
}
