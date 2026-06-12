<?php

declare(strict_types=1);

use ForgePanel\Web\Core\HttpClient;

// Otvrdnute opcije moraju uvijek tražiti TLS verifikaciju i samo https.
$opts = HttpClient::hardenedOptions();
T::assertSame(true, $opts[CURLOPT_SSL_VERIFYPEER], 'SSL_VERIFYPEER uključen');
T::assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST], 'SSL_VERIFYHOST = 2');
T::assertSame(false, $opts[CURLOPT_FOLLOWLOCATION], 'redirecti se ne prate automatski');
T::assertSame(CURLPROTO_HTTPS, $opts[CURLOPT_PROTOCOLS], 'samo https protokol');

// Anti-SSRF: javne https mete prolaze, interne/loopback/private/non-https padaju.
// Pozitivan slučaj koristi javni IP literal (bez DNS ovisnosti u testu).
T::assert(HttpClient::isSafePublicUrl('https://1.1.1.1/whmcs'), 'javni https IP prolazi');
T::assert(!HttpClient::isSafePublicUrl('http://example.com/x'), 'http odbijen');
T::assert(!HttpClient::isSafePublicUrl('https://127.0.0.1/x'), 'loopback IP odbijen');
T::assert(!HttpClient::isSafePublicUrl('https://10.0.0.5/x'), 'private 10/8 odbijen');
T::assert(!HttpClient::isSafePublicUrl('https://192.168.1.1/x'), 'private 192.168 odbijen');
T::assert(!HttpClient::isSafePublicUrl('https://169.254.169.254/latest/meta-data'), 'cloud metadata IP odbijen');
T::assert(!HttpClient::isSafePublicUrl('https://[::1]/x'), 'IPv6 loopback odbijen');
T::assert(!HttpClient::isSafePublicUrl('gopher://example.com/x'), 'ne-https shema odbijena');
