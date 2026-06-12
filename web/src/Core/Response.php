<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

final class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        self::securityHeaders();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(int $status, string $error): never
    {
        self::json(['ok' => false, 'error' => $error], $status);
    }

    public static function ok(mixed $data = null, int $status = 200): never
    {
        self::json(['ok' => true, 'data' => $data], $status);
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        // style-src 'unsafe-inline': UI koristi inline style atribute (progress width); script-src ostaje strogo 'self'.
        // base-uri/form-action 'self' i object-src 'none' zatvaraju injection vektore; upgrade-insecure-requests.
        header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; upgrade-insecure-requests");
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    }
}
