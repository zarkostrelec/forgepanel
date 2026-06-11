<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * terminal.exec — sandboxirani web terminal. Admin: izvršava u host shellu.
 * Klijent (kad admin uključi): privremeni Docker container s mountanim SAMO
 * svojim vhost direktorijem i svojim userom — ne vidi sustav. Prvi panel s
 * per-klijent terminalom koji ne može vidjeti host (poglavlje 14.10).
 */
final class TerminalExec extends Operation
{
    private const CLIENT_IMAGE = 'public.ecr.aws/docker/library/alpine:3.20';
    private const TIMEOUT_S = 30;
    private const MAX_OUTPUT = 65536;

    public function validate(array $params): void
    {
        Validator::oneOf($params['mode'] ?? null, ['admin', 'client'], 'mode');
        if (!is_string($params['command'] ?? null) || $params['command'] === '' || strlen($params['command']) > 4096) {
            throw new ValidationException('command je obavezan (max 4096)');
        }
        if (($params['mode'] ?? '') === 'client') {
            Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
            Validator::fqdn($params['domain'] ?? null);
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $command = (string) $params['command'];

        if ($params['mode'] === 'admin') {
            // Admin: puni shell na hostu (preko sh -c, ali kroz array proc_open — bez interpolacije)
            $result = Proc::run(['sh', '-c', $command], timeout_s: self::TIMEOUT_S);
            return $this->trim($result);
        }

        // Klijent: izolirani container, mount SAMO vhost roota kao /home/app, bez mreže
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;
        Validator::vhostPath($vhost_root);
        $sys_user = 'vh_' . $vhost_id;
        $pw = posix_getpwnam($sys_user);
        if ($pw === false) {
            throw new ValidationException('Sistemski user ne postoji');
        }

        $result = Proc::run([
            'docker', 'run', '--rm',
            '--network', 'none',                       // bez mreže
            '--memory', '128m', '--cpus', '0.5', '--pids-limit', '64',
            '--read-only',                             // rootfs read-only
            '--tmpfs', '/tmp:size=16m',
            '-v', "$vhost_root:/home/app:rw",          // SAMO vhost root, ničije drugo
            '-w', '/home/app',
            '-u', $pw['uid'] . ':' . $pw['gid'],       // kao vhost user, ne root
            '-e', 'HOME=/home/app',
            self::CLIENT_IMAGE,
            'sh', '-c', $command,
        ], timeout_s: self::TIMEOUT_S);

        return $this->trim($result);
    }

    /** @return array{exit_code: int, output: string} */
    private function trim(Proc $result): array
    {
        $output = $result->stdout . ($result->stderr !== '' ? "\n" . $result->stderr : '');
        return [
            'exit_code' => $result->exit_code,
            'output' => mb_substr($output, -self::MAX_OUTPUT),
        ];
    }
}
