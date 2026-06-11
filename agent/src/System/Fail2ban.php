<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\ValidationException;

/** fail2ban: pregled jailova i banova, ban/unban — sve kroz fail2ban-client. */
final class Fail2ban
{
    /** @return list<array{jail: string, banned: int, total: int, ips: list<string>}> */
    public static function jails(): array
    {
        $status = Proc::run(['fail2ban-client', 'status']);
        if (!$status->ok() || !preg_match('/Jail list:\s*(.+)/', $status->stdout, $m)) {
            return [];
        }
        $jails = array_filter(array_map('trim', explode(',', $m[1])));
        $result = [];
        foreach ($jails as $jail) {
            if (!preg_match('/^[a-z0-9_-]+$/i', $jail)) {
                continue;
            }
            $detail = Proc::run(['fail2ban-client', 'status', $jail])->stdout;
            preg_match('/Currently banned:\s*(\d+)/', $detail, $bm);
            preg_match('/Total banned:\s*(\d+)/', $detail, $tm);
            preg_match('/Banned IP list:\s*(.*)/', $detail, $im);
            $result[] = [
                'jail' => $jail,
                'banned' => (int) ($bm[1] ?? 0),
                'total' => (int) ($tm[1] ?? 0),
                'ips' => array_values(array_filter(array_map('trim', explode(' ', $im[1] ?? '')))),
            ];
        }
        return $result;
    }

    public static function ban(string $jail, string $ip): void
    {
        self::assertJail($jail);
        self::assertIp($ip);
        Proc::mustRun(['fail2ban-client', 'set', $jail, 'banip', $ip]);
    }

    public static function unban(string $jail, string $ip): void
    {
        self::assertJail($jail);
        self::assertIp($ip);
        Proc::mustRun(['fail2ban-client', 'set', $jail, 'unbanip', $ip]);
    }

    private static function assertJail(string $jail): void
    {
        if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $jail)) {
            throw new ValidationException('Neispravno ime jaila');
        }
    }

    private static function assertIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ValidationException('Neispravna IP adresa');
        }
    }
}
