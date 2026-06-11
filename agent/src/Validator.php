<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

/**
 * Centralna validacija parametara operacija — izvršava se PRIJE bilo kakvog
 * sistemskog poziva. Injection nemoguć po dizajnu: nakon validacije parametri
 * idu isključivo u proc_open s array argumentima.
 */
final class Validator
{
    public const VHOST_ROOT = '/var/www/vhosts';
    public const PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4'];

    public static function fqdn(mixed $value, string $field = 'domain'): string
    {
        if (!is_string($value)
            || strlen($value) > 253
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $value)
        ) {
            throw new ValidationException("$field nije ispravan FQDN");
        }
        return strtolower($value);
    }

    /**
     * Path mora biti unutar vhost roota — realpath provjera protiv symlink trikova.
     * Za još-nepostojeće pathove provjerava se realpath najbližeg postojećeg roditelja.
     */
    public static function vhostPath(mixed $value, string $field = 'path'): string
    {
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new ValidationException("$field nije ispravan path");
        }
        $probe = $value;
        while (($real = realpath($probe)) === false) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new ValidationException("$field nema postojećeg roditelja");
            }
            $probe = $parent;
        }
        if ($real !== self::VHOST_ROOT && !str_starts_with($real, self::VHOST_ROOT . '/')) {
            throw new ValidationException("$field je izvan " . self::VHOST_ROOT);
        }
        return $value;
    }

    public static function phpVersion(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::PHP_VERSIONS, true)) {
            throw new ValidationException('php_version nije podržana');
        }
        return $value;
    }

    /** Sigurni identifikator: DB imena, sistemski useri, imena servisa. */
    public static function identifier(mixed $value, string $field, int $max = 64): string
    {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,' . ($max - 1) . '}$/', $value)) {
            throw new ValidationException("$field nije ispravan identifikator");
        }
        return $value;
    }

    public static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new ValidationException("$field mora biti pozitivan cijeli broj");
        }
        return $value;
    }

    /** @param list<string> $allowed */
    public static function oneOf(mixed $value, array $allowed, string $field): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ValidationException("$field mora biti jedan od: " . implode(', ', $allowed));
        }
        return $value;
    }
}
