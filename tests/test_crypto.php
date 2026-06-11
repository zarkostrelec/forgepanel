<?php

declare(strict_types=1);

use ForgePanel\Web\Core\Config;
use ForgePanel\Web\Core\Crypto;

// In-memory config za test
$ini = tempnam(sys_get_temp_dir(), 'fpcfg');
file_put_contents($ini, "app_secret = \"test-secret-koji-je-dovoljno-dug-123\"\n");
$crypto = new Crypto(Config::load($ini));

$secret = 'cf_token_AbCdEf1234567890_tajni';
$enc = $crypto->encrypt($secret);
T::assert($enc !== $secret, 'ciphertext != plaintext');
T::assert(base64_decode($enc, true) !== false, 'ciphertext je valjan base64');
T::assertSame($secret, $crypto->decrypt($enc), 'decrypt vraća original');

// Dva enkriptiranja istog teksta daju različit ciphertext (nonce)
T::assert($crypto->encrypt($secret) !== $crypto->encrypt($secret), 'nonce čini ciphertext nedeterministicnim');

// Krivi ključ ne dekriptira
$ini2 = tempnam(sys_get_temp_dir(), 'fpcfg');
file_put_contents($ini2, "app_secret = \"drugi-potpuno-razlicit-secret-999\"\n");
$other = new Crypto(Config::load($ini2));
T::assertThrows(\RuntimeException::class, fn () => $other->decrypt($enc), 'krivi ključ baca iznimku');
T::assertThrows(\RuntimeException::class, fn () => $crypto->decrypt('nije!validan!base64!!!'), 'oštećeni ciphertext baca iznimku');

unlink($ini);
unlink($ini2);
