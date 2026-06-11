<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/** Čista logika politika updatea — testabilna bez sustava. */
final class UpdatePolicy
{
    /**
     * Je li trenutak unutar maintenance windowa.
     * @param list<int> $window_days ISO dani (1=pon … 7=ned)
     */
    public static function inWindow(string $window_start, string $window_end, array $window_days, ?int $now = null): bool
    {
        $now ??= time();
        $day = (int) date('N', $now);
        if (!in_array($day, $window_days, true)) {
            return false;
        }
        $time = date('H:i:s', $now);
        return $time >= $window_start && $time <= $window_end;
    }

    /**
     * Major skok (PHP 8.4→8.5, MariaDB 11→12) — NIKAD automatski.
     * Debian verzije mogu imati epoch (1:11.8.2-...) — uspoređuje se
     * prva numerička grupa nakon epocha; za X.Y sheme i druga.
     */
    public static function isMajorJump(string $current, string $available): bool
    {
        $parse = static function (string $version): array {
            $version = preg_replace('/^\d+:/', '', $version) ?? $version; // makni epoch
            preg_match('/^(\d+)(?:\.(\d+))?/', $version, $m);
            return [(int) ($m[1] ?? 0), (int) ($m[2] ?? 0)];
        };
        [$cur_major, $cur_minor] = $parse($current);
        [$av_major, $av_minor] = $parse($available);

        if ($cur_major !== $av_major) {
            return true;
        }
        // Za jednoznamenkaste majore (PHP 8.x, BIND 9.x) i minor je "major" skok
        return $cur_major < 10 && $cur_minor !== $av_minor;
    }
}
