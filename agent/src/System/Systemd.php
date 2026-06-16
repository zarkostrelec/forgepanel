<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

final class Systemd
{
    private const UNIT_RE = '/^[a-zA-Z0-9:._@-]+$/';

    public static function isActive(string $unit): bool
    {
        self::assertUnit($unit);
        return Proc::run(['systemctl', 'is-active', '--quiet', $unit])->ok();
    }

    public static function reload(string $unit): void
    {
        self::assertUnit($unit);
        Proc::mustRun(['systemctl', 'reload', $unit]);
    }

    public static function restart(string $unit): void
    {
        self::assertUnit($unit);
        Proc::mustRun(['systemctl', 'restart', $unit]);
    }

    public static function start(string $unit): void
    {
        self::assertUnit($unit);
        Proc::mustRun(['systemctl', 'start', $unit]);
    }

    public static function stop(string $unit): void
    {
        self::assertUnit($unit);
        Proc::mustRun(['systemctl', 'stop', $unit]);
    }

    public static function enableNow(string $unit): void
    {
        self::assertUnit($unit);
        Proc::mustRun(['systemctl', 'enable', '--now', $unit]);
    }

    public static function daemonReload(): void
    {
        Proc::mustRun(['systemctl', 'daemon-reload']);
    }

    /** @return array<string, string> */
    public static function show(string $unit): array
    {
        self::assertUnit($unit);
        $out = Proc::mustRun(['systemctl', 'show', $unit,
            '--property=LoadState,ActiveState,SubState,MainPID,MemoryCurrent,CPUUsageNSec,ActiveEnterTimestamp'])->stdout;
        $props = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $props[$k] = $v;
            }
        }
        return $props;
    }

    /**
     * cgroup v2 resource limiti kroz unit drop-in (CPUQuota/MemoryMax/TasksMax)
     * — resource kvote po vhostu bez CloudLinuxa.
     */
    public static function writeResourceDropin(string $unit, int $cpu_quota_pct, int $memory_max_bytes, int $tasks_max): void
    {
        self::assertUnit($unit);
        $dir = "/etc/systemd/system/{$unit}.d";
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $content = "[Service]\n"
            . "CPUQuota={$cpu_quota_pct}%\n"
            . "MemoryMax={$memory_max_bytes}\n"
            . "TasksMax={$tasks_max}\n";
        file_put_contents("$dir/forgepanel-quota.conf", $content);
        self::daemonReload();
    }

    private static function assertUnit(string $unit): void
    {
        if (!preg_match(self::UNIT_RE, $unit)) {
            throw new \InvalidArgumentException("Neispravno ime unita: $unit");
        }
    }
}
