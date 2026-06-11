<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Jedino mjesto u agentu koje pokreće vanjske procese.
 * Isključivo proc_open s array argumentima — string interpolacija u shell
 * ne postoji nigdje u kodu, injection je nemoguć po dizajnu.
 */
final class Proc
{
    public function __construct(
        public readonly int $exit_code,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function ok(): bool
    {
        return $this->exit_code === 0;
    }

    /**
     * @param list<string> $argv
     * @param ?\Closure(string): void $on_line  live output (task log / SSE)
     */
    public static function run(array $argv, ?string $stdin = null, int $timeout_s = 300, ?\Closure $on_line = null): self
    {
        $process = proc_open($argv, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C']);

        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open nije uspio: ' . $argv[0]);
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = $stderr = '';
        $deadline = microtime(true) + $timeout_s;

        while (true) {
            $read = array_filter([$pipes[1], $pipes[2]], is_resource(...));
            $read = array_filter($read, static fn ($p) => !feof($p));
            if ($read === []) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
                throw new \RuntimeException('Timeout: ' . implode(' ', $argv));
            }
            $write = $except = [];
            if (@stream_select($read, $write, $except, 1) > 0) {
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, 65536);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    if ($pipe === $pipes[1]) {
                        $stdout .= $chunk;
                        $on_line?->__invoke($chunk);
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        return new self(proc_close($process), $stdout, $stderr);
    }

    /** @param list<string> $argv */
    public static function mustRun(array $argv, ?string $stdin = null, int $timeout_s = 300, ?\Closure $on_line = null): self
    {
        $result = self::run($argv, $stdin, $timeout_s, $on_line);
        if (!$result->ok()) {
            throw new \RuntimeException(
                $argv[0] . ' exit ' . $result->exit_code . ': ' . trim($result->stderr ?: $result->stdout)
            );
        }
        return $result;
    }
}
