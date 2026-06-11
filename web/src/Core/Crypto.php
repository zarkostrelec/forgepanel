<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Simetrična enkripcija osjetljivih vrijednosti (Cloudflare API tokeni i sl.).
 * libsodium secretbox; ključ iz panel configa (app_secret).
 */
final class Crypto
{
    public function __construct(private readonly Config $config)
    {
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        $key = $this->key();
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Neispravan ciphertext');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('Dekripcija nije uspjela (krivi ključ ili oštećeni podaci)');
        }
        return $plain;
    }

    private function key(): string
    {
        // app_secret iz configa → 32-bajtni ključ (BLAKE2b)
        return sodium_crypto_generichash($this->config->get('app_secret', 'forgepanel-dev-secret-change-me'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
