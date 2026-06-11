<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\DockerCli;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** docker.action — start/stop/restart/rm + status/logs za postojeći container. */
final class DockerAction extends Operation
{
    public function validate(array $params): void
    {
        DockerCli::assertName($params['name'] ?? null);
        Validator::oneOf($params['action'] ?? null, ['start', 'stop', 'restart', 'rm', 'status', 'logs'], 'action');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $name = DockerCli::assertName($params['name']);
        $action = (string) $params['action'];

        if ($action === 'status') {
            $inspect = DockerCli::inspect($name);
            return [
                'exists' => $inspect !== null,
                'state' => $inspect['State']['Status'] ?? 'missing',
                'started_at' => $inspect['State']['StartedAt'] ?? null,
            ];
        }
        if ($action === 'logs') {
            $tail = max(10, min(2000, (int) ($params['tail'] ?? 200)));
            $logs = Proc::run(['docker', 'logs', '--tail', (string) $tail, $name], timeout_s: 30);
            return ['logs' => mb_substr($logs->stdout . $logs->stderr, -200000)];
        }
        if ($action === 'rm') {
            Proc::mustRun(['docker', 'rm', '-f', $name], timeout_s: 120);
            return ['removed' => $name];
        }

        Proc::mustRun(['docker', $action, $name], timeout_s: 120);
        return ['name' => $name, 'action' => $action];
    }
}
