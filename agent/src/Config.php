<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Config ne postoji: $path");
        }
        $perms = fileperms($path) & 0o777;
        if ($perms & 0o077) {
            throw new \RuntimeException("$path smije biti čitljiv samo rootu (chmod 600)");
        }
        $parsed = parse_ini_file($path, false, INI_SCANNER_TYPED);
        if ($parsed === false) {
            throw new \RuntimeException("Neispravan INI: $path");
        }
        return new self(array_map(strval(...), $parsed));
    }

    public function get(string $key, ?string $default = null): string
    {
        $value = $this->values[$key] ?? $default;
        if ($value === null) {
            throw new \RuntimeException("Nedostaje config ključ: $key");
        }
        return $value;
    }
}
