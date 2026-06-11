<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

final class Autoloader
{
    public static function register(string $base_dir): void
    {
        spl_autoload_register(static function (string $class) use ($base_dir): void {
            $prefix = 'ForgePanel\\Agent\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $base_dir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
