<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

final class Apt
{
    private const ENV_ARGS = ['env', 'DEBIAN_FRONTEND=noninteractive'];
    // Ne čekaj beskonačno na dpkg/apt lock (npr. unattended-upgrades u pozadini) —
    // padni s jasnom greškom nakon 5 min umjesto višeminutnog "visenja" taska.
    private const LOCK_ARGS = ['-o', 'DPkg::Lock::Timeout=300'];

    public static function update(?\Closure $on_line = null): void
    {
        Proc::mustRun([...self::ENV_ARGS, 'apt-get', ...self::LOCK_ARGS, 'update', '-q'], timeout_s: 600, on_line: $on_line);
    }

    /** @param list<string> $packages */
    public static function install(array $packages, ?\Closure $on_line = null): void
    {
        self::assertPackages($packages);
        Proc::mustRun(
            [...self::ENV_ARGS, 'apt-get', ...self::LOCK_ARGS, 'install', '-y', '-q', '--no-install-recommends', ...$packages],
            timeout_s: 1800,
            on_line: $on_line
        );
    }

    /** @param list<string> $packages */
    public static function upgradeOnly(array $packages, ?\Closure $on_line = null): void
    {
        self::assertPackages($packages);
        Proc::mustRun(
            [...self::ENV_ARGS, 'apt-get', ...self::LOCK_ARGS, 'install', '-y', '-q', '--only-upgrade', ...$packages],
            timeout_s: 1800,
            on_line: $on_line
        );
    }

    /** @return array<string, array{current: string, available: string, suite: string}> */
    public static function listUpgradable(): array
    {
        $out = Proc::mustRun(['apt', 'list', '--upgradable'], timeout_s: 120)->stdout;
        $result = [];
        foreach (explode("\n", $out) as $line) {
            // npr: nginx/resolute-security 1.27.4-1 amd64 [upgradable from: 1.27.3-1]
            if (preg_match('#^([^/]+)/(\S+)\s+(\S+)\s+\S+\s+\[upgradable from:\s+(\S+)\]#', $line, $m)) {
                $result[$m[1]] = ['current' => $m[4], 'available' => $m[3], 'suite' => $m[2]];
            }
        }
        return $result;
    }

    public static function installedVersion(string $package): ?string
    {
        self::assertPackages([$package]);
        $result = Proc::run(['dpkg-query', '-W', '-f=${Version}', $package]);
        return $result->ok() && $result->stdout !== '' ? $result->stdout : null;
    }

    /** @param list<string> $packages */
    private static function assertPackages(array $packages): void
    {
        foreach ($packages as $package) {
            if (!preg_match('/^[a-z0-9][a-z0-9+.-]+$/', $package)) {
                throw new \InvalidArgumentException("Neispravno ime paketa: $package");
            }
        }
    }
}
