<?php

declare(strict_types=1);

use ForgePanel\Web\Core\WebAuthn;

/**
 * Testovi WebAuthn verifikacije — simulirani autentikator s pravim ključevima
 * (ECDSA P-256 i RSA preko openssl, Ed25519 preko sodiuma).
 */

// ---------------------------------------------------------------- test helperi

/** Minimalni CBOR encoder (samo za testove). */
function cbor_enc(mixed $value): string
{
    $head = static function (int $major, int $len): string {
        return match (true) {
            $len < 24 => chr(($major << 5) | $len),
            $len < 256 => chr(($major << 5) | 24) . chr($len),
            $len < 65536 => chr(($major << 5) | 25) . pack('n', $len),
            default => chr(($major << 5) | 26) . pack('N', $len),
        };
    };
    if (is_int($value)) {
        return $value >= 0 ? $head(0, $value) : $head(1, -1 - $value);
    }
    if (is_string($value)) {
        // U testovima: binarne vrijednosti šaljemo kao bytes, ključeve mapa kao text
        return $head(2, strlen($value)) . $value;
    }
    if ($value instanceof CborText) {
        return $head(3, strlen($value->s)) . $value->s;
    }
    if (is_array($value)) {
        if (array_is_list($value)) {
            return $head(4, count($value)) . implode('', array_map(cbor_enc(...), $value));
        }
        $out = $head(5, count($value));
        foreach ($value as $k => $v) {
            $out .= is_int($k) ? cbor_enc($k) : cbor_enc(new CborText((string) $k));
            $out .= cbor_enc($v);
        }
        return $out;
    }
    throw new InvalidArgumentException('cbor_enc: nepodržan tip');
}

final class CborText
{
    public function __construct(public readonly string $s)
    {
    }
}

function make_auth_data(string $rp_id, int $sign_count, ?string $cred_id = null, ?array $cose_key = null, int $flags = 0x01): string
{
    $data = hash('sha256', $rp_id, true) . chr($cred_id !== null ? $flags | 0x40 : $flags) . pack('N', $sign_count);
    if ($cred_id !== null && $cose_key !== null) {
        $data .= str_repeat("\x00", 16) . pack('n', strlen($cred_id)) . $cred_id . cbor_enc($cose_key);
    }
    return $data;
}

function client_data(string $type, string $challenge, string $origin): string
{
    return WebAuthn::b64uEncode(json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin]));
}

const RP_ID = 'panel.example.hr';
const ORIGIN = 'https://panel.example.hr:8443';

// ---------------------------------------------------------------- base64url

T::assertSame('aGVsbG8', WebAuthn::b64uEncode('hello'), 'b64u encode bez paddinga');
T::assertSame("\xfb\xff", WebAuthn::b64uDecode(WebAuthn::b64uEncode("\xfb\xff")), 'b64u roundtrip binarno');
T::assertThrows(RuntimeException::class, fn () => WebAuthn::b64uDecode('ne!valja'), 'b64u odbija nevaljan input');

// ---------------------------------------------------------------- CBOR dekoder

$off = 0;
T::assertSame(23, WebAuthn::cborDecode(cbor_enc(23), $off), 'cbor mali uint');
$off = 0;
T::assertSame(-500, WebAuthn::cborDecode(cbor_enc(-500), $off), 'cbor negint');
$off = 0;
T::assertSame([1, 2, 3], WebAuthn::cborDecode(cbor_enc([1, 2, 3]), $off), 'cbor array');
$off = 0;
T::assertSame(['a' => 1, -2 => 'xy'], WebAuthn::cborDecode(cbor_enc(['a' => 1, -2 => 'xy']), $off), 'cbor mapa s int/text ključevima');
$off = 0;
T::assertThrows(RuntimeException::class, function () {
    $off = 0;
    WebAuthn::cborDecode("\xbf", $off); // indefinite mapa
}, 'cbor indefinite length odbijen');
$off = 0;
T::assertThrows(RuntimeException::class, function () {
    $off = 0;
    WebAuthn::cborDecode(substr(cbor_enc(['a' => 1]), 0, 2), $off);
}, 'cbor truncated odbijen');

// ---------------------------------------------------------------- ES256 registracija + assertion

$ec = openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1']]);
$ec_details = openssl_pkey_get_details($ec);
$cose_ec = [1 => 2, 3 => -7, -1 => 1, -2 => $ec_details['ec']['x'], -3 => $ec_details['ec']['y']];

$cred_id = random_bytes(32);
$challenge = WebAuthn::generateChallenge();
$reg_response = [
    'credential_id' => WebAuthn::b64uEncode($cred_id),
    'attestation_object' => WebAuthn::b64uEncode(cbor_enc([
        'fmt' => new CborText('none'),
        'attStmt' => [],
        'authData' => make_auth_data(RP_ID, 0, $cred_id, $cose_ec),
    ])),
    'client_data_json' => client_data('webauthn.create', $challenge, ORIGIN),
];

$cred = WebAuthn::verifyRegistration($reg_response, $challenge, RP_ID, ORIGIN);
T::assertSame(WebAuthn::b64uEncode($cred_id), $cred['credential_id'], 'registracija vraća credential_id');
T::assertSame(WebAuthn::ALG_ES256, $cred['alg'], 'registracija prepoznaje ES256');
T::assert(str_contains($cred['public_key'], 'BEGIN PUBLIC KEY'), 'ES256 ključ pretvoren u PEM');
T::assertSame($ec_details['key'], $cred['public_key'], 'COSE→PEM identičan openssl exportu');

// kriva challenge / origin / rp / tip
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyRegistration($reg_response, WebAuthn::generateChallenge(), RP_ID, ORIGIN), 'registracija: kriva challenge');
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyRegistration($reg_response, $challenge, RP_ID, 'https://zlo.example'), 'registracija: krivi origin');
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyRegistration($reg_response, $challenge, 'drugi.example.hr', ORIGIN), 'registracija: krivi rpId');
$bad_type = $reg_response;
$bad_type['client_data_json'] = client_data('webauthn.get', $challenge, ORIGIN);
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyRegistration($bad_type, $challenge, RP_ID, ORIGIN), 'registracija: krivi type');

// assertion
$assert_challenge = WebAuthn::generateChallenge();
$auth_data = make_auth_data(RP_ID, 5);
$cdj = client_data('webauthn.get', $assert_challenge, ORIGIN);
openssl_sign($auth_data . hash('sha256', WebAuthn::b64uDecode($cdj), true), $sig, $ec, OPENSSL_ALGO_SHA256);
$assertion = [
    'authenticator_data' => WebAuthn::b64uEncode($auth_data),
    'client_data_json' => $cdj,
    'signature' => WebAuthn::b64uEncode($sig),
];

T::assertSame(5, WebAuthn::verifyAssertion($assertion, $assert_challenge, RP_ID, ORIGIN, $cred['public_key'], $cred['alg'], 2), 'ES256 assertion prolazi, vraća novi counter');
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyAssertion($assertion, $assert_challenge, RP_ID, ORIGIN, $cred['public_key'], $cred['alg'], 5), 'sign_count regresija (kloniran ključ) odbijena');
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyAssertion($assertion, WebAuthn::generateChallenge(), RP_ID, ORIGIN, $cred['public_key'], $cred['alg'], 2), 'assertion: kriva challenge');

$tampered = $assertion;
$tampered['signature'] = WebAuthn::b64uEncode(substr($sig, 0, -1) . chr(ord($sig[-1]) ^ 0x01));
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyAssertion($tampered, $assert_challenge, RP_ID, ORIGIN, $cred['public_key'], $cred['alg'], 2), 'assertion: pokvaren potpis odbijen');

// UP flag mora biti postavljen
$no_up = make_auth_data(RP_ID, 6, flags: 0x00);
$cdj2 = client_data('webauthn.get', $assert_challenge, ORIGIN);
openssl_sign($no_up . hash('sha256', WebAuthn::b64uDecode($cdj2), true), $sig2, $ec, OPENSSL_ALGO_SHA256);
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyAssertion(
    ['authenticator_data' => WebAuthn::b64uEncode($no_up), 'client_data_json' => $cdj2, 'signature' => WebAuthn::b64uEncode($sig2)],
    $assert_challenge,
    RP_ID,
    ORIGIN,
    $cred['public_key'],
    $cred['alg'],
    2
), 'assertion: user-present flag obavezan');

// counter 0/0 (autentikator bez countera) dopušten
$zero_auth = make_auth_data(RP_ID, 0);
$cdj3 = client_data('webauthn.get', $assert_challenge, ORIGIN);
openssl_sign($zero_auth . hash('sha256', WebAuthn::b64uDecode($cdj3), true), $sig3, $ec, OPENSSL_ALGO_SHA256);
T::assertSame(0, WebAuthn::verifyAssertion(
    ['authenticator_data' => WebAuthn::b64uEncode($zero_auth), 'client_data_json' => $cdj3, 'signature' => WebAuthn::b64uEncode($sig3)],
    $assert_challenge,
    RP_ID,
    ORIGIN,
    $cred['public_key'],
    $cred['alg'],
    0
), 'assertion: 0/0 counter dopušten');

// ---------------------------------------------------------------- RS256

$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$rsa_details = openssl_pkey_get_details($rsa);
$cose_rsa = [1 => 3, 3 => -257, -1 => $rsa_details['rsa']['n'], -2 => $rsa_details['rsa']['e']];

$rsa_cred_id = random_bytes(16);
$rsa_challenge = WebAuthn::generateChallenge();
$rsa_cred = WebAuthn::verifyRegistration([
    'credential_id' => WebAuthn::b64uEncode($rsa_cred_id),
    'attestation_object' => WebAuthn::b64uEncode(cbor_enc([
        'fmt' => new CborText('packed'),
        'attStmt' => [],
        'authData' => make_auth_data(RP_ID, 1, $rsa_cred_id, $cose_rsa),
    ])),
    'client_data_json' => client_data('webauthn.create', $rsa_challenge, ORIGIN),
], $rsa_challenge, RP_ID, ORIGIN);
T::assertSame(WebAuthn::ALG_RS256, $rsa_cred['alg'], 'registracija prepoznaje RS256');
T::assertSame($rsa_details['key'], $rsa_cred['public_key'], 'RSA COSE→PEM identičan openssl exportu');

$rsa_auth = make_auth_data(RP_ID, 9);
$rsa_cdj = client_data('webauthn.get', $rsa_challenge, ORIGIN);
openssl_sign($rsa_auth . hash('sha256', WebAuthn::b64uDecode($rsa_cdj), true), $rsa_sig, $rsa, OPENSSL_ALGO_SHA256);
T::assertSame(9, WebAuthn::verifyAssertion(
    ['authenticator_data' => WebAuthn::b64uEncode($rsa_auth), 'client_data_json' => $rsa_cdj, 'signature' => WebAuthn::b64uEncode($rsa_sig)],
    $rsa_challenge,
    RP_ID,
    ORIGIN,
    $rsa_cred['public_key'],
    $rsa_cred['alg'],
    1
), 'RS256 assertion prolazi');

// ---------------------------------------------------------------- Ed25519

$ed = sodium_crypto_sign_keypair();
$ed_pub = sodium_crypto_sign_publickey($ed);
$cose_ed = [1 => 1, 3 => -8, -1 => 6, -2 => $ed_pub];

$ed_cred_id = random_bytes(20);
$ed_challenge = WebAuthn::generateChallenge();
$ed_cred = WebAuthn::verifyRegistration([
    'credential_id' => WebAuthn::b64uEncode($ed_cred_id),
    'attestation_object' => WebAuthn::b64uEncode(cbor_enc([
        'fmt' => new CborText('none'),
        'attStmt' => [],
        'authData' => make_auth_data(RP_ID, 0, $ed_cred_id, $cose_ed),
    ])),
    'client_data_json' => client_data('webauthn.create', $ed_challenge, ORIGIN),
], $ed_challenge, RP_ID, ORIGIN);
T::assertSame(WebAuthn::ALG_EDDSA, $ed_cred['alg'], 'registracija prepoznaje Ed25519');

$ed_auth = make_auth_data(RP_ID, 3);
$ed_cdj = client_data('webauthn.get', $ed_challenge, ORIGIN);
$ed_sig = sodium_crypto_sign_detached($ed_auth . hash('sha256', WebAuthn::b64uDecode($ed_cdj), true), sodium_crypto_sign_secretkey($ed));
T::assertSame(3, WebAuthn::verifyAssertion(
    ['authenticator_data' => WebAuthn::b64uEncode($ed_auth), 'client_data_json' => $ed_cdj, 'signature' => WebAuthn::b64uEncode($ed_sig)],
    $ed_challenge,
    RP_ID,
    ORIGIN,
    $ed_cred['public_key'],
    $ed_cred['alg'],
    1
), 'Ed25519 assertion prolazi');

// nepodržan algoritam (ES384, kty EC2 alg -35)
$bad_alg_id = random_bytes(16);
$bad_alg_challenge = WebAuthn::generateChallenge();
T::assertThrows(RuntimeException::class, fn () => WebAuthn::verifyRegistration([
    'credential_id' => WebAuthn::b64uEncode($bad_alg_id),
    'attestation_object' => WebAuthn::b64uEncode(cbor_enc([
        'fmt' => new CborText('none'),
        'attStmt' => [],
        'authData' => make_auth_data(RP_ID, 0, $bad_alg_id, [1 => 2, 3 => -35, -1 => 2, -2 => random_bytes(48), -3 => random_bytes(48)]),
    ])),
    'client_data_json' => client_data('webauthn.create', $bad_alg_challenge, ORIGIN),
], $bad_alg_challenge, RP_ID, ORIGIN), 'registracija: nepodržan algoritam odbijen');
