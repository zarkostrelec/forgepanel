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
        // JSON_INVALID_UTF8_SUBSTITUTE: poruke iz vanjskih alata (npr. php-fpm -t stderr) mogu
        // imati ne-UTF8 bajtove — bez ovog json_encode vrati false → prazan body → "bad_response"
        $out = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $out !== false ? $out : '{"ok":false,"error":"encode_error"}';
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
        // HSTS samo za panel host: BEZ includeSubDomains (panel je često subdomena
        // klijentove domene — pinnali bismo tuđe poddomene na 2 godine) i BEZ preload
        // (signal za preload listu bez stvarne registracije je pogrešan).
        header('Strict-Transport-Security: max-age=63072000');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');
    }
}
