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

    /**
     * Promet iz nginx access.log (current + .1): broj zahtjeva u zadnjih 7 dana
     * i 24 satna bucketa za sparkline. Tail ograničava trošak na velikim logovima.
     * @return array{0: int, 1: list<int>} [total_7d, spark(24)]
     */
    public static function traffic(string $domain): array
    {
        $base = '/var/www/vhosts/' . $domain . '/logs/access.log';
        $now = time();
        $cut7 = $now - 7 * 86400;
        $cut24 = $now - 24 * 3600;
        $total7 = 0;
        $spark = array_fill(0, 24, 0);
        $months = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
            'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

        foreach ([$base, $base . '.1'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $res = Proc::run(['tail', '-n', '300000', '--', $file], timeout_s: 60);
            if (!$res->ok()) {
                continue;
            }
            $line = strtok($res->stdout, "\n");
            while ($line !== false) {
                if (preg_match('#\[(\d{2})/(\w{3})/(\d{4}):(\d{2}):(\d{2}):(\d{2})#', $line, $m)
                    && isset($months[$m[2]])) {
                    $ts = mktime((int) $m[4], (int) $m[5], (int) $m[6], $months[$m[2]], (int) $m[1], (int) $m[3]);
                    if ($ts !== false && $ts >= $cut7) {
                        $total7++;
                        if ($ts >= $cut24) {
                            $bucket = 23 - (int) floor(($now - $ts) / 3600);
                            if ($bucket >= 0 && $bucket < 24) {
                                $spark[$bucket]++;
                            }
                        }
                    }
                }
                $line = strtok("\n");
            }
        }
        return [$total7, $spark];
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
