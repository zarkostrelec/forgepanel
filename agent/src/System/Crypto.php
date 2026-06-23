<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\Config;

/**
 * Dekripcija osjetljivih vrijednosti na strani agenta (Cloudflare API tokeni) —
 * isti algoritam kao web Core\Crypto (libsodium secretbox, ključ = BLAKE2b(app_secret)).
 *
 * app_secret se uzima iz agent.ini (novije instalacije), a ako ga ondje nema —
 * čita se iz /etc/forgepanel/web.ini (agent je root pa smije; web.ini ga uvijek ima).
 */
final class Crypto
{
    private const WEB_INI = '/etc/forgepanel/web.ini';
    private const MIN_SECRET_LEN = 16;

    public function __construct(private readonly string $app_secret)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $secret = $config->get('app_secret', '');
        if ($secret === '' && is_file(self::WEB_INI)) {
            $parsed = parse_ini_file(self::WEB_INI, false, INI_SCANNER_TYPED);
            if (is_array($parsed) && isset($parsed['app_secret'])) {
                $secret = (string) $parsed['app_secret'];
            }
        }
        return new self($secret);
    }

    public function decrypt(string $encoded): string
    {
        if (strlen($this->app_secret) < self::MIN_SECRET_LEN) {
            throw new \RuntimeException('app_secret nije dostupan agentu — dekripcija odbijena.');
        }
        $key = sodium_crypto_generichash($this->app_secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Neispravan ciphertext.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('Dekripcija nije uspjela (krivi ključ ili oštećeni podaci).');
        }
        return $plain;
    }
}
