<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

final class Ufw
{
    public static function allowPort(int $port, string $proto = 'tcp', ?string $from = null): void
    {
        self::assertPort($port);
        self::assertProto($proto);
        $argv = ['ufw', 'allow'];
        if ($from !== null) {
            self::assertCidr($from);
            $argv = [...$argv, 'from', $from, 'to', 'any', 'port', (string) $port, 'proto', $proto];
        } else {
            $argv[] = "$port/$proto";
        }
        Proc::mustRun($argv);
    }

    public static function denyPort(int $port, string $proto = 'tcp'): void
    {
        self::assertPort($port);
        self::assertProto($proto);
        Proc::mustRun(['ufw', '--force', 'delete', 'allow', "$port/$proto"]);
    }

    private static function assertPort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException("Neispravan port: $port");
        }
    }

    private static function assertProto(string $proto): void
    {
        if (!in_array($proto, ['tcp', 'udp'], true)) {
            throw new \InvalidArgumentException("Neispravan protokol: $proto");
        }
    }

    private static function assertCidr(string $cidr): void
    {
        $parts = explode('/', $cidr, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false
            || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > 128))
        ) {
            throw new \InvalidArgumentException("Neispravan CIDR: $cidr");
        }
    }
}
