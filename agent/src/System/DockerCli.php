<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\ValidationException;

/** Docker Engine kroz CLI (array argv — injection nemoguć). */
final class DockerCli
{
    public static function ensureInstalled(?\Closure $log = null): void
    {
        if (Proc::run(['docker', '--version'])->ok()) {
            return;
        }
        $log?->__invoke("Instaliram Docker Engine\n");
        Apt::install(['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-compose-plugin'], $log);
        Systemd::enableNow('docker');
    }

    public static function assertName(mixed $name): string
    {
        if (!is_string($name) || !preg_match('/^fp-\d+-[a-z0-9][a-z0-9_-]{0,40}$/', $name)) {
            throw new ValidationException('Neispravno ime containera (fp-<sub>-<ime>)');
        }
        return $name;
    }

    public static function assertImage(mixed $image): string
    {
        if (!is_string($image) || !preg_match('#^[a-z0-9][a-z0-9._/:@-]{1,200}$#', $image)) {
            throw new ValidationException('Neispravno ime imagea');
        }
        return $image;
    }

    /** @return array<string, mixed>|null inspect JSON ili null ako ne postoji */
    public static function inspect(string $name): ?array
    {
        $result = Proc::run(['docker', 'inspect', self::assertName($name)]);
        if (!$result->ok()) {
            return null;
        }
        $parsed = json_decode($result->stdout, true);
        return is_array($parsed) && isset($parsed[0]) ? $parsed[0] : null;
    }
}
