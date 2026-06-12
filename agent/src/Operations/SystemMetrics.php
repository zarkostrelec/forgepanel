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
        $total = $meminfo['MemTotal'] ?? 0;
        // razrada za monitoring legendu: aplikacije / cache+buffer / slobodno
        $cached = ($meminfo['Cached'] ?? 0) + ($meminfo['Buffers'] ?? 0) + ($meminfo['SReclaimable'] ?? 0);
        $free = $meminfo['MemFree'] ?? 0;
        $cores = self::coresSample();

        return [
            'load' => sys_getloadavg() ?: [0.0, 0.0, 0.0],
            'cpu_count' => self::cpuCount(),
            'cpu_pct' => $cores === [] ? null : round(array_sum($cores) / count($cores), 1),
            'cores' => $cores,
            'mem_total_bytes' => $total,
            'mem_available_bytes' => $meminfo['MemAvailable'] ?? 0,
            'mem_apps_bytes' => max(0, $total - $free - $cached),
            'mem_cache_bytes' => $cached,
            'mem_free_bytes' => $free,
            'disk_total_bytes' => (int) disk_total_space('/'),
            'disk_free_bytes' => (int) disk_free_space('/'),
            'uptime_s' => (int) (float) explode(' ', (string) file_get_contents('/proc/uptime'))[0],
        ];
    }

    /**
     * Per-core CPU % iz dva uzorka /proc/stat s 250 ms razmaka.
     * @return list<float>
     */
    private static function coresSample(): array
    {
        $read = static function (): array {
            $out = [];
            foreach (explode("\n", (string) @file_get_contents('/proc/stat')) as $line) {
                if (preg_match('/^cpu(\d+)\s+(.+)$/', $line, $m)) {
                    $f = array_map('floatval', preg_split('/\s+/', trim($m[2])));
                    $idle = ($f[3] ?? 0) + ($f[4] ?? 0); // idle + iowait
                    $out[(int) $m[1]] = [array_sum($f), $idle];
                }
            }
            return $out;
        };
        $a = $read();
        if ($a === []) {
            return [];
        }
        usleep(250000);
        $b = $read();

        $cores = [];
        foreach ($a as $i => [$total1, $idle1]) {
            [$total2, $idle2] = $b[$i] ?? [$total1, $idle1];
            $dt = $total2 - $total1;
            $cores[] = $dt > 0 ? round(max(0.0, min(100.0, (1 - ($idle2 - $idle1) / $dt) * 100)), 1) : 0.0;
        }
        return $cores;
    }

    private static function cpuCount(): int
    {
        return max(1, preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')));
    }
}
