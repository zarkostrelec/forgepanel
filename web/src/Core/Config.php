<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(string $path): self
    {
        $parsed = is_file($path) ? parse_ini_file($path, false, INI_SCANNER_TYPED) : false;
        if ($parsed === false) {
            throw new \RuntimeException("Config nije čitljiv: $path");
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
