<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;

/** system.top — top procesi po CPU-u (monitoring "Top procesi" tablica). */
final class SystemTop extends Operation
{
    public function validate(array $params): void
    {
    }

    public function execute(array $params, TaskContext $context): array
    {
        $result = Proc::mustRun(['ps', '-eo', 'pid,user:16,pcpu,pmem,rss,comm,args', '--sort=-pcpu', '--no-headers']);
        $rows = [];
        foreach (array_slice(explode("\n", trim($result->stdout)), 0, 12) as $line) {
            $p = preg_split('/\s+/', trim($line), 7);
            if (count($p) < 7) {
                continue;
            }
            $rows[] = [
                'pid' => (int) $p[0],
                'user' => $p[1],
                'cpu_pct' => (float) $p[2],
                'mem_pct' => (float) $p[3],
                'rss_bytes' => (int) $p[4] * 1024,
                'comm' => $p[5],
                'args' => mb_substr($p[6], 0, 120),
            ];
        }
        return ['processes' => $rows];
    }
}
