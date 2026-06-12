<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

use ForgePanel\Agent\Operations\Operation;

/**
 * Glavna petlja agenta: UNIX socket server (sinkrone, kratke operacije)
 * + task queue worker (dugotrajne operacije, asinkrono) + systemd watchdog.
 */
final class Agent
{
    private const SOCKET_PATH = '/run/forgepanel/agent.sock';
    private const MAX_REQUEST_BYTES = 1_048_576;

    private bool $running = true;
    private float $last_watchdog = 0.0;
    private float $last_tick_error_log = 0.0;

    private readonly Db $db;

    public function __construct(private readonly Config $config)
    {
        $this->db = new Db($config);
    }

    public function run(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->running = false);
        pcntl_signal(SIGINT, fn () => $this->running = false);

        $server = $this->bindSocket();
        $registry = new OperationRegistry($this->config, $this->db);
        $audit = new AuditLogger($this->db);
        $worker = new TaskWorker($this->db, $registry, $audit);
        $scheduler = new Scheduler($this->db, $this->config);

        $this->sdNotify('READY=1');

        while ($this->running) {
            $this->watchdogPing();

            $read = [$server];
            $write = $except = [];
            if (@stream_select($read, $write, $except, 1) > 0) {
                $client = @stream_socket_accept($server, 0);
                if ($client !== false) {
                    $this->handleClient($client, $registry, $audit);
                }
            }

            // Jedan neuspjeli tick (npr. pad DB konekcije: MariaDB restart,
            // wait_timeout) NE SMIJE srušiti daemon — inače systemd vrti restart
            // petlju, a taskovi zauvijek ostaju 'pending'. Logiraj u journald i
            // nastavi; sljedeći pdo() otvara svježu konekciju.
            try {
                $worker->tick();
            } catch (\Throwable $e) {
                $this->db->reconnect();
                $this->logTickError('worker', $e);
            }
            try {
                $scheduler->tick();
            } catch (\Throwable $e) {
                $this->db->reconnect();
                $this->logTickError('scheduler', $e);
            }
        }

        fclose($server);
        @unlink(self::SOCKET_PATH);
    }

    /** @return resource */
    private function bindSocket()
    {
        $dir = dirname(self::SOCKET_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        @unlink(self::SOCKET_PATH);

        $server = stream_socket_server('unix://' . self::SOCKET_PATH, $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("Socket bind nije uspio: $errstr");
        }

        // root:fpanel 0660 — samo panelov web user smije pričati s agentom
        $fpanel = posix_getgrnam($this->config->get('socket_group', 'fpanel'));
        if ($fpanel !== false) {
            chgrp(self::SOCKET_PATH, $fpanel['name']);
        }
        chmod(self::SOCKET_PATH, 0o660);

        return $server;
    }

    /** @param resource $client */
    private function handleClient($client, OperationRegistry $registry, AuditLogger $audit): void
    {
        stream_set_timeout($client, 5);
        $raw = fgets($client, self::MAX_REQUEST_BYTES);

        $response = ['ok' => false, 'error' => 'bad_request'];
        if (is_string($raw)) {
            $response = $this->dispatch($raw, $registry, $audit);
        }

        fwrite($client, json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        fclose($client);
    }

    /** @return array<string, mixed> */
    private function dispatch(string $raw, OperationRegistry $registry, AuditLogger $audit): array
    {
        try {
            $request = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'error' => 'invalid_json'];
        }

        $op_code = $request['op'] ?? null;
        $params = $request['params'] ?? [];
        if (!is_string($op_code) || !is_array($params)) {
            return ['ok' => false, 'error' => 'invalid_request'];
        }

        $operation = $registry->resolve($op_code);
        if ($operation === null) {
            // Nepoznat op-code = odbij + log. Bez iznimke.
            $audit->log('agent', 'op.rejected', ['op' => $op_code]);
            return ['ok' => false, 'error' => 'unknown_operation'];
        }

        if ($operation->isLongRunning()) {
            return ['ok' => false, 'error' => 'use_task_queue'];
        }

        try {
            $operation->validate($params);
            $data = $operation->execute($params, new TaskContext());
            $audit->log('agent', 'op.executed', ['op' => $op_code]);
            return ['ok' => true, 'data' => $data];
        } catch (ValidationException $e) {
            $audit->log('agent', 'op.validation_failed', ['op' => $op_code, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'validation: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            $audit->log('agent', 'op.failed', ['op' => $op_code, 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Throttle: kod trajno nedostupne DB ne preplavi journald (max 1 zapis / 30 s). */
    private function logTickError(string $where, \Throwable $e): void
    {
        $now = microtime(true);
        if ($now - $this->last_tick_error_log < 30.0) {
            return;
        }
        $this->last_tick_error_log = $now;
        error_log("forge-agentd: $where tick error: " . $e->getMessage());
    }

    private function watchdogPing(): void
    {
        $now = microtime(true);
        if ($now - $this->last_watchdog >= 10.0) {
            $this->sdNotify('WATCHDOG=1');
            $this->last_watchdog = $now;
        }
    }

    private function sdNotify(string $state): void
    {
        $socket_path = getenv('NOTIFY_SOCKET');
        if (!is_string($socket_path) || $socket_path === '') {
            return;
        }
        $socket = @socket_create(AF_UNIX, SOCK_DGRAM, 0);
        if ($socket === false) {
            return;
        }
        @socket_sendto($socket, $state, strlen($state), 0, $socket_path);
        socket_close($socket);
    }
}
