<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Per-vhost statistike za "Siteovi" listu: zauzeće diska (du -sb) i heuristička
 * detekcija tipa aplikacije iz docroota (WordPress/WooCommerce, Laravel, Node,
 * Astro, statički, generički PHP). Bez mreže, samo lokalni filesystem.
 */
final class VhostStats
{
    /**
     * @return array{0: ?int, 1: ?string} [disk_bytes, app_type]
     */
    public static function collect(string $vhost_root, string $docroot): array
    {
        $disk = null;
        if (is_dir($vhost_root)) {
            $res = Proc::run(['du', '-sb', '--', $vhost_root], timeout_s: 120);
            if ($res->ok() && preg_match('/^(\d+)/', $res->stdout, $m)) {
                $disk = (int) $m[1];
            }
        }
        return [$disk, self::detectApp($docroot)];
    }

    /** Heuristika nad docrootom (i njegovim roditeljem za artisan/package.json). */
    public static function detectApp(string $docroot): ?string
    {
        if (!is_dir($docroot)) {
            return null;
        }
        $parent = \dirname($docroot);

        // WordPress / WooCommerce
        if (is_file("$docroot/wp-config.php") || is_file("$docroot/wp-load.php") || is_file("$parent/wp-config.php")) {
            return is_dir("$docroot/wp-content/plugins/woocommerce") ? 'woocommerce' : 'wordpress';
        }

        // Laravel (artisan je tipično iznad public/ docroota)
        if (is_file("$docroot/artisan") || is_file("$parent/artisan")) {
            return 'laravel';
        }

        // Node / Astro / Next (package.json u docrootu ili iznad)
        $pkg = is_file("$docroot/package.json") ? "$docroot/package.json"
            : (is_file("$parent/package.json") ? "$parent/package.json" : null);
        if ($pkg !== null) {
            $json = json_decode((string) @file_get_contents($pkg), true);
            $deps = array_merge(
                array_keys((array) ($json['dependencies'] ?? [])),
                array_keys((array) ($json['devDependencies'] ?? [])),
            );
            if (in_array('astro', $deps, true)) {
                return 'astro';
            }
            if (in_array('next', $deps, true)) {
                return 'nextjs';
            }
            return 'node';
        }

        // Generički PHP (composer projekt) prije statičkog
        if (is_file("$docroot/composer.json")) {
            return 'php';
        }

        $has_php = (glob("$docroot/*.php") ?: []) !== [];
        if (!$has_php && is_file("$docroot/index.html")) {
            return 'static';
        }
        return $has_php ? 'php' : null;
    }
}
