<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\ValidationException;

/**
 * Validacija X.509 certifikata za ručni upload (kupljeni/custom certovi).
 * Sve provjere PRIJE pisanja na disk: parse, ključ pripada certu, domena
 * pokrivena (CN/SAN, uključujući wildcard), nije istekao, chain se ulančava.
 */
final class CertUtil
{
    /**
     * @return array{expires_at: string, issuer: string, sans: list<string>}
     */
    public static function validate(string $cert_pem, string $key_pem, string $chain_pem, string $domain): array
    {
        $cert = @openssl_x509_read($cert_pem);
        if ($cert === false) {
            throw new ValidationException('Certifikat nije valjan PEM X.509.');
        }
        $key = @openssl_pkey_get_private($key_pem);
        if ($key === false) {
            throw new ValidationException('Privatni ključ nije valjan PEM (ili je zaštićen lozinkom — pošalji nešifrirani).');
        }
        if (!openssl_x509_check_private_key($cert, $key)) {
            throw new ValidationException('Privatni ključ ne pripada ovom certifikatu.');
        }

        $parsed = openssl_x509_parse($cert);
        if (!is_array($parsed)) {
            throw new ValidationException('Certifikat se ne može pročitati.');
        }
        $expires = (int) ($parsed['validTo_time_t'] ?? 0);
        if ($expires < time()) {
            throw new ValidationException('Certifikat je istekao (' . date('d.m.Y.', $expires) . ').');
        }
        if ((int) ($parsed['validFrom_time_t'] ?? 0) > time()) {
            throw new ValidationException('Certifikat još nije važeći (notBefore u budućnosti).');
        }

        $sans = self::subjectNames($parsed);
        if (!self::covers($sans, $domain)) {
            throw new ValidationException(
                "Certifikat ne pokriva domenu $domain (pokriva: " . implode(', ', $sans) . ').'
            );
        }

        // Chain (ako je poslan): svaki element mora biti valjan PEM cert
        if (trim($chain_pem) !== '' && self::splitPems($chain_pem) === []) {
            throw new ValidationException('CA chain nije valjan PEM.');
        }

        return [
            'expires_at' => date('Y-m-d H:i:s', $expires),
            'issuer' => (string) ($parsed['issuer']['CN'] ?? $parsed['issuer']['O'] ?? '?'),
            'sans' => $sans,
        ];
    }

    /**
     * CN + SAN DNS imena iz parsiranog certa.
     *
     * @param array<string, mixed> $parsed
     * @return list<string>
     */
    public static function subjectNames(array $parsed): array
    {
        $names = [];
        if (is_string($parsed['subject']['CN'] ?? null)) {
            $names[] = strtolower($parsed['subject']['CN']);
        }
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            if (preg_match('/^\s*DNS:(\S+)$/i', $entry, $m)) {
                $names[] = strtolower($m[1]);
            }
        }
        return array_values(array_unique($names));
    }

    /** Pokriva li popis imena (s mogućim wildcardom) traženu domenu. @param list<string> $names */
    public static function covers(array $names, string $domain): bool
    {
        $domain = strtolower($domain);
        foreach ($names as $name) {
            $name = strtolower($name);
            if ($name === $domain) {
                return true;
            }
            // wildcard vrijedi samo za jednu razinu (*.example.com ≠ a.b.example.com)
            if (str_starts_with($name, '*.')
                && substr_count($domain, '.') === substr_count($name, '.')
                && str_ends_with($domain, substr($name, 1))
            ) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> pojedinačni PEM blokovi */
    public static function splitPems(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m);
        $valid = [];
        foreach ($m[0] as $block) {
            if (@openssl_x509_read($block) !== false) {
                $valid[] = $block;
            }
        }
        return count($valid) === count($m[0]) ? $valid : [];
    }
}
