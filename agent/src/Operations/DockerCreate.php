<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\DockerCli;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * docker.create — pull + create + start s resource limitima (cgroup v2)
 * i port mapiranjem isključivo na 127.0.0.1 (izlaz prema svijetu ide kroz nginx).
 */
final class DockerCreate extends Operation
{
    private const RESTART_POLICIES = ['no', 'on-failure', 'unless-stopped', 'always'];

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        DockerCli::assertName($params['name'] ?? null);
        DockerCli::assertImage($params['image'] ?? null);
        foreach ($params['ports'] ?? [] as $port) {
            $host = $port['host'] ?? 0;
            $container = $port['container'] ?? 0;
            if (!is_int($host) || $host < 1024 || $host > 65535 || !is_int($container) || $container < 1 || $container > 65535) {
                throw new ValidationException('Portovi moraju biti 1024–65535 (host) / 1–65535 (container)');
            }
        }
        foreach ($params['env'] ?? [] as $key => $value) {
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', (string) $key) || !is_string($value)
                || preg_match('/[\x00-\x1f]/', $value) || strlen($value) > 1024
            ) {
                throw new ValidationException('Neispravan env par');
            }
        }
        Validator::oneOf($params['restart_policy'] ?? 'unless-stopped', self::RESTART_POLICIES, 'restart_policy');
        $memory = $params['memory_bytes'] ?? 268435456;
        if (!is_int($memory) || $memory < 16777216 || $memory > 17179869184) {
            throw new ValidationException('memory_bytes mora biti 16 MB – 16 GB');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        DockerCli::ensureInstalled($context->output(...));
        $name = DockerCli::assertName($params['name']);
        $image = DockerCli::assertImage($params['image']);

        if (DockerCli::inspect($name) !== null) {
            throw new ValidationException("Container $name već postoji");
        }

        $context->output("docker pull $image");
        Proc::mustRun(['docker', 'pull', $image], timeout_s: 1800, on_line: $context->output(...));
        $context->progress(60);

        $argv = ['docker', 'run', '-d', '--name', $name,
            '--restart', (string) ($params['restart_policy'] ?? 'unless-stopped'),
            '--memory', (string) ($params['memory_bytes'] ?? 268435456),
            '--cpus', sprintf('%.2f', min(8.0, max(0.1, (float) ($params['cpus'] ?? 1.0)))),
            '--pids-limit', '256',
        ];
        foreach ($params['ports'] ?? [] as $port) {
            // samo loopback — javni promet ide kroz nginx proxy mapiranje
            $argv[] = '-p';
            $argv[] = "127.0.0.1:{$port['host']}:{$port['container']}";
        }
        foreach ($params['env'] ?? [] as $key => $value) {
            $argv[] = '-e';
            $argv[] = "$key=$value";
        }
        $argv[] = $image;

        $context->output('docker run ' . $name);
        $container_id = trim(Proc::mustRun($argv, timeout_s: 300)->stdout);
        $context->progress(100);

        return ['container_id' => substr($container_id, 0, 12), 'name' => $name];
    }
}
