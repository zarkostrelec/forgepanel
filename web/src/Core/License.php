<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Enforcement licence na NODU (hard lock). Lokalna provjera ed25519-potpisanog tokena
 * (bez mreže — token osvježava agent heartbeat). Pravila su namjerno FAIL-OPEN i s
 * iznimkama da se panel nikad ne zaključa zbog bug-a ili da master ostane upotrebljiv:
 *
 *  - MASTER (ima dist_signing_key) → nikad se ne zaključava.
 *  - Node BEZ konfiguriranog update_servera → nije spojen na distribuciju → ne zaključava se
 *    (public installeri postavljaju update_server na mothership pa za njih lock VRIJEDI).
 *  - Dopuštene rute (auth, license, distribution, branding) uvijek prolaze — da se panel
 *    može aktivirati i korisnik prijaviti.
 *  - Novi node ima bootstrap grace od instalacije dok agent ne dohvati trial token.
 */
final class License
{
    private const BOOTSTRAP_GRACE_S = 72 * 3600;
    private const TOKEN_KEYS = ['key', 'tier', 'status', 'expires_at', 'fingerprint', 'issued_at'];
    private const ALLOW_PREFIXES = [
        '/api/v1/auth', '/api/v1/license', '/api/v1/distribution', '/api/v1/branding',
    ];

    public static function shouldBlock(App $app, Request $request): bool
    {
        try {
            if (!str_starts_with($request->path, '/api/')) {
                return false; // statiku servira nginx, ne PHP
            }
            foreach (self::ALLOW_PREFIXES as $p) {
                if (str_starts_with($request->path, $p)) {
                    return false;
                }
            }
            if (self::settingRaw($app, 'dist_signing_key') !== null) {
                return false; // ovo je MASTER
            }
            if (self::str($app, 'update_server') === '') {
                return false; // nije spojen na distribuciju
            }
            return !self::isValid($app);
        } catch (\Throwable $e) {
            error_log('forgepanel: license enforce fail-open: ' . $e->getMessage());
            return false;
        }
    }

    /** Lokalna provjera potpisanog tokena + bootstrap grace. */
    public static function isValid(App $app): bool
    {
        $pub = self::str($app, 'update_pubkey');
        $tokenRaw = self::str($app, 'license_token');
        if ($pub !== '' && $tokenRaw !== '') {
            $token = json_decode($tokenRaw, true);
            if (is_array($token) && isset($token['license'], $token['signature']) && is_array($token['license'])) {
                $p = $token['license'];
                $okSig = sodium_crypto_sign_verify_detached(
                    base64_decode((string) $token['signature']),
                    self::canonical($p),
                    base64_decode($pub)
                );
                if ($okSig) {
                    $status = (string) ($p['status'] ?? '');
                    $exp = (string) ($p['expires_at'] ?? '');
                    $notExpired = $exp === '' || strtotime($exp) > time();
                    return in_array($status, ['active', 'trial'], true) && $notExpired;
                }
            }
        }
        // Nema važećeg tokena → grace od instalacije (da agent stigne dohvatiti trial)
        $installed = self::str($app, 'installed_at');
        if ($installed === '') {
            self::store($app, 'installed_at', gmdate('Y-m-d\TH:i:s\Z'));
            return true;
        }
        return (time() - (int) strtotime($installed)) < self::BOOTSTRAP_GRACE_S;
    }

    /** @param array<string,mixed> $p */
    private static function canonical(array $p): string
    {
        $out = [];
        foreach (self::TOKEN_KEYS as $k) {
            $out[$k] = (string) ($p[$k] ?? '');
        }
        return (string) json_encode($out, JSON_UNESCAPED_SLASHES);
    }

    private static function settingRaw(App $app, string $key): ?string
    {
        $row = $app->db->one('SELECT value FROM settings WHERE `key` = ?', [$key]);
        return $row === null ? null : (string) $row['value'];
    }

    private static function str(App $app, string $key): string
    {
        $raw = self::settingRaw($app, $key);
        if ($raw === null) {
            return '';
        }
        $v = json_decode($raw, true);
        return is_string($v) ? $v : '';
    }

    private static function store(App $app, string $key, string $value): void
    {
        $app->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$key, json_encode($value)]
        );
    }
}
