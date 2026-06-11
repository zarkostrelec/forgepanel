<?php

declare(strict_types=1);

use ForgePanel\Agent\Operations\WebmailSetup;
use ForgePanel\Web\Core\SignedToken;

// ---------------------------------------------------------------- SignedToken (phpMyAdmin auto-login)

$secret = 'test-secret';
$token = SignedToken::create(['db_user_id' => 7, 'db' => 'moja_baza'], $secret, 60, now: 1_000_000);

$payload = SignedToken::verify($token, $secret, now: 1_000_030);
T::assert(is_array($payload), 'token se verificira unutar TTL-a');
T::assertSame(7, $payload['db_user_id'], 'payload nosi db_user_id');
T::assertSame('moja_baza', $payload['db'], 'payload nosi ime baze');
T::assert(is_string($payload['jti']) && strlen($payload['jti']) === 32, 'jti generiran (32 hex)');

T::assertSame(null, SignedToken::verify($token, $secret, now: 1_000_061), 'istekao token odbijen');
T::assertSame(null, SignedToken::verify($token, 'krivi-secret', now: 1_000_030), 'krivi secret odbijen');
T::assertSame(null, SignedToken::verify('nije.token', $secret), 'smeće odbijeno');
T::assertSame(null, SignedToken::verify('', $secret), 'prazan string odbijen');

// tamper: promjena payloada ruši potpis
[$body, $sig] = explode('.', $token);
$fake_body = rtrim(strtr(base64_encode(json_encode(
    json_decode(strtr($body, '-_', '+/') . '==', true) ?: ['db_user_id' => 999, 'exp' => 2_000_000, 'jti' => str_repeat('a', 32)]
)), '+/', '-_'), '=');
T::assertSame(null, SignedToken::verify($fake_body . '.' . $sig, $secret, now: 1_000_030), 'tampered payload odbijen');

// dva tokena = različiti jti (jednokratnost po tokenu)
$token2 = SignedToken::create(['db_user_id' => 7], $secret, 60, now: 1_000_000);
T::assert(
    SignedToken::verify($token2, $secret, now: 1_000_001)['jti'] !== $payload['jti'],
    'svaki token ima vlastiti jti'
);

// ---------------------------------------------------------------- Webmail nginx template

$conf = WebmailSetup::nginxTemplate('webmail.example.hr');
T::assert(str_contains($conf, 'server_name webmail.example.hr;'), 'webmail template: server_name');
T::assert(str_contains($conf, '/etc/forgepanel/ssl/webmail.example.hr/fullchain.pem'), 'webmail template: ssl path');
T::assert(str_contains($conf, 'fastcgi_pass unix:/run/php/fpm-webmail.sock;'), 'webmail template: vlastiti FPM pool');
T::assert(str_contains($conf, '/.well-known/acme-challenge/'), 'webmail template: ACME webroot');
T::assert(str_contains($conf, 'return 301 https://'), 'webmail template: http→https redirect');
T::assert(str_contains($conf, 'location ~ ^/(config|temp|logs|SQL|bin)/ { deny all; }'), 'webmail template: interni direktoriji blokirani');
