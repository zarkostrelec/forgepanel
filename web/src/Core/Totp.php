<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/** TOTP (RFC 6238) — SHA-1, 6 znamenki, 30 s period, ±1 window. Bez vanjskih ovisnosti. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        // 32 base32 znaka = 160 bita (256 % 32 == 0, nema modulo biasa)
        $secret = '';
        foreach (str_split(random_bytes(32)) as $byte) {
            $secret .= self::ALPHABET[ord($byte) % 32];
        }
        return $secret;
    }

    public static function verify(string $secret, string $code, int $window = 1, ?int $now = null): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $now ??= time();
        $counter = intdiv($now, 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $counter + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function code(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1_000_000;
        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    public static function otpauthUri(string $secret, string $email, string $issuer = 'ForgePanel'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer)
        );
    }

    private static function base32Decode(string $input): string
    {
        $bits = '';
        foreach (str_split(strtoupper($input)) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr((int) bindec($byte));
            }
        }
        return $output;
    }
}
