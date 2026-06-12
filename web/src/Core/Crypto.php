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

    /** Poznata dev/placeholder vrijednost koja se NIKAD ne smije koristiti u produkciji. */
    private const FORBIDDEN_SECRET = 'forgepanel-dev-secret-change-me';
    private const MIN_SECRET_LEN = 16;

    private function key(): string
    {
        // app_secret MORA biti konfiguriran (installer ga generira: openssl rand -hex 32).
        // Nikad ne padaj na poznati default — to bi tiho srozalo svu enkripciju na javno
        // poznat ključ (Cloudflare/Anthropic tokeni, DB lozinke za pMA bi bili dekriptabilni).
        $secret = $this->config->get('app_secret', '');
        if ($secret === '' || $secret === self::FORBIDDEN_SECRET || strlen($secret) < self::MIN_SECRET_LEN) {
            throw new \RuntimeException(
                'app_secret nije ispravno konfiguriran (prazan, default ili kraći od '
                . self::MIN_SECRET_LEN . ' znakova) — enkripcija odbijena.'
            );
        }
        // app_secret iz configa → 32-bajtni ključ (BLAKE2b)
        return sodium_crypto_generichash($secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
