<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Fail2ban;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Ufw;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/** firewall.action — ufw status/allow/deny + fail2ban jail pregled i ban/unban. */
final class FirewallAction extends Operation
{
    public function validate(array $params): void
    {
        Validator::oneOf(
            $params['action'] ?? null,
            ['ufw_status', 'ufw_allow', 'ufw_deny', 'f2b_jails', 'f2b_ban', 'f2b_unban'],
            'action'
        );
    }

    public function execute(array $params, TaskContext $context): array
    {
        return match ($params['action']) {
            'ufw_status' => ['rules' => $this->ufwStatus()],
            'ufw_allow' => $this->ufwChange($params, true),
            'ufw_deny' => $this->ufwChange($params, false),
            'f2b_jails' => ['jails' => Fail2ban::jails()],
            'f2b_ban' => $this->f2bBan($params, true),
            'f2b_unban' => $this->f2bBan($params, false),
            default => throw new ValidationException('nepoznata akcija'),
        };
    }

    /** @return list<string> */
    private function ufwStatus(): array
    {
        $out = Proc::run(['ufw', 'status', 'numbered'])->stdout;
        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l) => str_contains($l, 'ALLOW') || str_contains($l, 'DENY')));
    }

    /** @return array<string, mixed> */
    private function ufwChange(array $params, bool $allow): array
    {
        $port = (int) ($params['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new ValidationException('port mora biti 1–65535');
        }
        $proto = $params['proto'] ?? 'tcp';
        $allow ? Ufw::allowPort($port, $proto, $params['from'] ?? null) : Ufw::denyPort($port, $proto);
        return ['port' => $port, 'allow' => $allow];
    }

    /** @return array<string, mixed> */
    private function f2bBan(array $params, bool $ban): array
    {
        $jail = (string) ($params['jail'] ?? '');
        $ip = (string) ($params['ip'] ?? '');
        $ban ? Fail2ban::ban($jail, $ip) : Fail2ban::unban($jail, $ip);
        return ['jail' => $jail, 'ip' => $ip, 'banned' => $ban];
    }
}
