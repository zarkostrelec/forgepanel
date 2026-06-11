<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Potpisani kratkoživući token (HMAC-SHA256, app_secret) — npr. phpMyAdmin auto-login.
 * Format: base64url(JSON payload) . "." . base64url(HMAC). Payload nosi exp + jti;
 * jednokratnost (jti) provjerava pozivatelj (settings tablica), ovdje je samo potpis i istek.
 */
final class SignedToken
{
    /** @param array<string, mixed> $payload */
    public static function create(array $payload, string $secret, int $ttl_s, ?int $now = null): string
    {
        $payload['exp'] = ($now ?? time()) + $ttl_s;
        $payload['jti'] = bin2hex(random_bytes(16));
        $body = WebAuthn::b64uEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        return $body . '.' . WebAuthn::b64uEncode(hash_hmac('sha256', $body, $secret, true));
    }

    /** @return array<string, mixed>|null payload, ili null ako je potpis kriv ili token istekao */
    public static function verify(string $token, string $secret, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        try {
            $expected = hash_hmac('sha256', $parts[0], $secret, true);
            if (!hash_equals($expected, WebAuthn::b64uDecode($parts[1]))) {
                return null;
            }
            $payload = json_decode(WebAuthn::b64uDecode($parts[0]), true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($payload) || !is_string($payload['jti'] ?? null)
            || !is_int($payload['exp'] ?? null) || $payload['exp'] < ($now ?? time())
        ) {
            return null;
        }
        return $payload;
    }
}
