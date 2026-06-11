<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\TaskContext;

/** system.metrics — trenutni CPU/RAM/disk/load za monitoring modul, sve iz /proc. */
final class SystemMetrics extends Operation
{
    public function validate(array $params): void
    {
    }

    public function execute(array $params, TaskContext $context): array
    {
        $meminfo = [];
        foreach (explode("\n", (string) file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $meminfo[$m[1]] = (int) $m[2] * 1024;
            }
        }

        return [
            'load' => sys_getloadavg() ?: [0.0, 0.0, 0.0],
            'cpu_count' => self::cpuCount(),
            'mem_total_bytes' => $meminfo['MemTotal'] ?? 0,
            'mem_available_bytes' => $meminfo['MemAvailable'] ?? 0,
            'disk_total_bytes' => (int) disk_total_space('/'),
            'disk_free_bytes' => (int) disk_free_space('/'),
            'uptime_s' => (int) (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0],
        ];
    }

    private static function cpuCount(): int
    {
        return max(1, preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')));
    }
}
