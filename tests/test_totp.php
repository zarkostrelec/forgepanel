<?php

declare(strict_types=1);

use ForgePanel\Web\Core\Totp;

// RFC 6238 test vektor (SHA-1, secret "12345678901234567890"), skraćen na 6 znamenki
$rfc_secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

T::assertSame('287082', Totp::code($rfc_secret, intdiv(59, 30)), 'TOTP RFC vektor t=59');
T::assertSame('081804', Totp::code($rfc_secret, intdiv(1111111109, 30)), 'TOTP RFC vektor t=1111111109');
T::assertSame('050471', Totp::code($rfc_secret, intdiv(1111111111, 30)), 'TOTP RFC vektor t=1111111111');

T::assert(Totp::verify($rfc_secret, '287082', now: 59), 'verify prihvaća ispravan kod');
T::assert(Totp::verify($rfc_secret, '287082', now: 59 + 29), 'verify tolerira ±1 window');
T::assert(!Totp::verify($rfc_secret, '000000', now: 59), 'verify odbija krivi kod');
T::assert(!Totp::verify($rfc_secret, '28708', now: 59), 'verify odbija kod krive duljine');
T::assert(!Totp::verify($rfc_secret, 'abcdef', now: 59), 'verify odbija ne-znamenke');

$secret = Totp::generateSecret();
T::assertSame(32, strlen($secret), 'generirani secret ima 32 znaka');
T::assert(preg_match('/^[A-Z2-7]+$/', $secret) === 1, 'secret je valjan base32');
T::assert(str_contains(Totp::otpauthUri($secret, 'a@b.hr'), 'otpauth://totp/'), 'otpauth URI format');
