<?php

declare(strict_types=1);

/**
 * ForgePanel test runner — bez vanjskih ovisnosti.
 * Pokretanje: php tests/run.php
 */

error_reporting(E_ALL);

require __DIR__ . '/../agent/src/Autoloader.php';
require __DIR__ . '/../web/src/Autoloader.php';

ForgePanel\Agent\Autoloader::register(__DIR__ . '/../agent/src');
ForgePanel\Web\Autoloader::register(__DIR__ . '/../web/src');

final class T
{
    public static int $passed = 0;
    /** @var list<string> */
    public static array $failures = [];

    public static function assert(bool $condition, string $name): void
    {
        if ($condition) {
            self::$passed++;
            return;
        }
        self::$failures[] = $name;
        echo "FAIL: $name\n";
    }

    public static function assertSame(mixed $expected, mixed $actual, string $name): void
    {
        self::assert(
            $expected === $actual,
            $name . ' (očekivano: ' . var_export($expected, true) . ', dobiveno: ' . var_export($actual, true) . ')'
        );
    }

    public static function assertThrows(string $exception_class, \Closure $fn, string $name): void
    {
        try {
            $fn();
            self::assert(false, $name . ' (iznimka nije bačena)');
        } catch (\Throwable $e) {
            self::assert($e instanceof $exception_class, $name . ' (dobiveno: ' . $e::class . ' — ' . $e->getMessage() . ')');
        }
    }
}

foreach (glob(__DIR__ . '/test_*.php') ?: [] as $file) {
    echo '— ' . basename($file) . "\n";
    require $file;
}

echo "\n" . T::$passed . ' prošlo, ' . count(T::$failures) . " palo.\n";
exit(T::$failures === [] ? 0 : 1);
