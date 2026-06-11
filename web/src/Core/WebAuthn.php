<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * WebAuthn (FIDO2) drugi faktor — čisti PHP, bez vanjskih ovisnosti.
 * Podržani algoritmi: ES256 (-7), RS256 (-257), Ed25519 (-8).
 * Attestation se ne provjerava (traži se 'none') — ključ registrira sam vlasnik računa,
 * pa nas zanima samo posjedovanje ključa, ne proizvođač autentikatora.
 */
final class WebAuthn
{
    public const ALG_ES256 = -7;
    public const ALG_EDDSA = -8;
    public const ALG_RS256 = -257;

    private const FLAG_UP = 0x01; // user present
    private const FLAG_AT = 0x40; // attested credential data

    // ---------------------------------------------------------------- base64url

    public static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $input): string
    {
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('invalid_base64url');
        }
        return $decoded;
    }

    public static function generateChallenge(): string
    {
        return self::b64uEncode(random_bytes(32));
    }

    // ---------------------------------------------------------------- registracija

    /**
     * Verificira odgovor autentikatora na registraciju (navigator.credentials.create).
     *
     * @param array{credential_id: string, attestation_object: string, client_data_json: string} $response b64url polja
     * @return array{credential_id: string, public_key: string, alg: int, sign_count: int}
     */
    public static function verifyRegistration(array $response, string $challenge, string $rp_id, string $origin): array
    {
        self::verifyClientData((string) $response['client_data_json'], 'webauthn.create', $challenge, $origin);

        $offset = 0;
        $attestation = self::cborDecode(self::b64uDecode((string) $response['attestation_object']), $offset);
        if (!is_array($attestation) || !isset($attestation['authData']) || !is_string($attestation['authData'])) {
            throw new \RuntimeException('invalid_attestation');
        }

        $auth = self::parseAuthData($attestation['authData'], $rp_id, require_attested: true);
        if (self::b64uEncode($auth['credential_id']) !== (string) $response['credential_id']) {
            throw new \RuntimeException('credential_id_mismatch');
        }

        [$public_key, $alg] = self::coseKeyToVerifier($auth['cose_key']);

        return [
            'credential_id' => self::b64uEncode($auth['credential_id']),
            'public_key' => $public_key,
            'alg' => $alg,
            'sign_count' => $auth['sign_count'],
        ];
    }

    // ---------------------------------------------------------------- prijava (assertion)

    /**
     * Verificira assertion (navigator.credentials.get). Vraća novi sign_count.
     *
     * @param array{authenticator_data: string, client_data_json: string, signature: string} $response b64url polja
     */
    public static function verifyAssertion(
        array $response,
        string $challenge,
        string $rp_id,
        string $origin,
        string $public_key,
        int $alg,
        int $stored_sign_count,
    ): int {
        $client_data_raw = self::verifyClientData((string) $response['client_data_json'], 'webauthn.get', $challenge, $origin);

        $auth_data = self::b64uDecode((string) $response['authenticator_data']);
        $auth = self::parseAuthData($auth_data, $rp_id, require_attested: false);

        $signed = $auth_data . hash('sha256', $client_data_raw, true);
        $signature = self::b64uDecode((string) $response['signature']);

        $valid = match ($alg) {
            self::ALG_ES256 => openssl_verify($signed, $signature, $public_key, OPENSSL_ALGO_SHA256) === 1,
            self::ALG_RS256 => openssl_verify($signed, $signature, $public_key, OPENSSL_ALGO_SHA256) === 1,
            self::ALG_EDDSA => strlen($signature) === SODIUM_CRYPTO_SIGN_BYTES
                && sodium_crypto_sign_verify_detached($signature, $signed, base64_decode($public_key, true) ?: ''),
            default => false,
        };
        if (!$valid) {
            throw new \RuntimeException('invalid_signature');
        }

        // Clone detekcija: counter mora rasti (0/0 = autentikator bez countera, dopušteno)
        if ($auth['sign_count'] !== 0 || $stored_sign_count !== 0) {
            if ($auth['sign_count'] <= $stored_sign_count) {
                throw new \RuntimeException('sign_count_regression');
            }
        }
        return $auth['sign_count'];
    }

    // ---------------------------------------------------------------- clientDataJSON

    /** Vraća sirovi clientDataJSON (potreban za potpis kod assertiona). */
    private static function verifyClientData(string $client_data_b64u, string $expected_type, string $challenge, string $origin): string
    {
        $raw = self::b64uDecode($client_data_b64u);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('invalid_client_data');
        }
        if (($data['type'] ?? '') !== $expected_type) {
            throw new \RuntimeException('client_data_type_mismatch');
        }
        if (!is_string($data['challenge'] ?? null) || !hash_equals($challenge, $data['challenge'])) {
            throw new \RuntimeException('challenge_mismatch');
        }
        if (($data['origin'] ?? '') !== $origin) {
            throw new \RuntimeException('origin_mismatch');
        }
        return $raw;
    }

    // ---------------------------------------------------------------- authenticator data

    /**
     * @return array{flags: int, sign_count: int, credential_id: string, cose_key: array<int|string, mixed>}
     */
    private static function parseAuthData(string $auth_data, string $rp_id, bool $require_attested): array
    {
        if (strlen($auth_data) < 37) {
            throw new \RuntimeException('auth_data_too_short');
        }
        if (!hash_equals(hash('sha256', $rp_id, true), substr($auth_data, 0, 32))) {
            throw new \RuntimeException('rp_id_mismatch');
        }
        $flags = ord($auth_data[32]);
        if (($flags & self::FLAG_UP) === 0) {
            throw new \RuntimeException('user_not_present');
        }
        $sign_count = unpack('N', substr($auth_data, 33, 4))[1];

        $credential_id = '';
        $cose_key = [];
        if ($require_attested) {
            if (($flags & self::FLAG_AT) === 0 || strlen($auth_data) < 55) {
                throw new \RuntimeException('attested_data_missing');
            }
            $id_len = unpack('n', substr($auth_data, 53, 2))[1];
            if ($id_len < 1 || $id_len > 1023 || strlen($auth_data) < 55 + $id_len) {
                throw new \RuntimeException('invalid_credential_id_length');
            }
            $credential_id = substr($auth_data, 55, $id_len);
            $offset = 55 + $id_len;
            $key = self::cborDecode($auth_data, $offset);
            if (!is_array($key)) {
                throw new \RuntimeException('invalid_cose_key');
            }
            $cose_key = $key;
        }

        return ['flags' => $flags, 'sign_count' => $sign_count, 'credential_id' => $credential_id, 'cose_key' => $cose_key];
    }

    // ---------------------------------------------------------------- COSE → verifikacijski ključ

    /**
     * Pretvara COSE ključ u oblik za verifikaciju: PEM (ES256/RS256) ili base64 raw (Ed25519).
     *
     * @param array<int|string, mixed> $cose
     * @return array{0: string, 1: int}
     */
    private static function coseKeyToVerifier(array $cose): array
    {
        $kty = $cose[1] ?? null;   // 1 OKP, 2 EC2, 3 RSA
        $alg = $cose[3] ?? null;

        if ($kty === 2 && $alg === self::ALG_ES256) {
            $crv = $cose[-1] ?? null;
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if ($crv !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                throw new \RuntimeException('invalid_ec2_key');
            }
            // SubjectPublicKeyInfo za prime256v1 + nekomprimirana točka
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            return [self::pem($der), self::ALG_ES256];
        }

        if ($kty === 3 && $alg === self::ALG_RS256) {
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (!is_string($n) || !is_string($e) || strlen($n) < 256) {
                throw new \RuntimeException('invalid_rsa_key');
            }
            $rsa = self::derSeq(self::derInt($n) . self::derInt($e));
            $der = self::derSeq(
                self::derSeq(hex2bin('06092a864886f70d010101') . "\x05\x00") . self::derBitString($rsa)
            );
            return [self::pem($der), self::ALG_RS256];
        }

        if ($kty === 1 && $alg === self::ALG_EDDSA) {
            $crv = $cose[-1] ?? null;
            $x = $cose[-2] ?? null;
            if ($crv !== 6 || !is_string($x) || strlen($x) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \RuntimeException('invalid_okp_key');
            }
            return [base64_encode($x), self::ALG_EDDSA];
        }

        throw new \RuntimeException('unsupported_algorithm');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function derLen(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = ltrim(pack('N', $len), "\0");
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function derSeq(string $content): string
    {
        return "\x30" . self::derLen(strlen($content)) . $content;
    }

    private static function derInt(string $bin): string
    {
        $bin = ltrim($bin, "\0");
        if ($bin === '' || (ord($bin[0]) & 0x80) !== 0) {
            $bin = "\0" . $bin;
        }
        return "\x02" . self::derLen(strlen($bin)) . $bin;
    }

    private static function derBitString(string $bin): string
    {
        return "\x03" . self::derLen(strlen($bin) + 1) . "\0" . $bin;
    }

    // ---------------------------------------------------------------- CBOR (RFC 8949, podskup)

    /**
     * Minimalni CBOR dekoder — definite-length uint/negint/bytes/text/array/map,
     * dovoljno za attestationObject i COSE ključeve. Indefinite length = odbij.
     */
    public static function cborDecode(string $bin, int &$offset): mixed
    {
        if ($offset >= strlen($bin)) {
            throw new \RuntimeException('cbor_truncated');
        }
        $initial = ord($bin[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($info === 31) {
            throw new \RuntimeException('cbor_indefinite_unsupported');
        }
        $value = self::cborUint($bin, $offset, $info);

        switch ($major) {
            case 0:
                return $value;
            case 1:
                return -1 - $value;
            case 2:
            case 3:
                if ($offset + $value > strlen($bin)) {
                    throw new \RuntimeException('cbor_truncated');
                }
                $str = substr($bin, $offset, $value);
                $offset += $value;
                return $str;
            case 4:
                $items = [];
                for ($i = 0; $i < $value; $i++) {
                    $items[] = self::cborDecode($bin, $offset);
                }
                return $items;
            case 5:
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $key = self::cborDecode($bin, $offset);
                    if (!is_int($key) && !is_string($key)) {
                        throw new \RuntimeException('cbor_invalid_map_key');
                    }
                    $map[$key] = self::cborDecode($bin, $offset);
                }
                return $map;
            case 6:
                // tag — preskoči, dekodiraj sadržaj
                return self::cborDecode($bin, $offset);
            default:
                return match ($info) {
                    20 => false,
                    21 => true,
                    22 => null,
                    default => throw new \RuntimeException('cbor_unsupported_simple'),
                };
        }
    }

    private static function cborUint(string $bin, int &$offset, int $info): int
    {
        $bytes = match (true) {
            $info < 24 => 0,
            $info === 24 => 1,
            $info === 25 => 2,
            $info === 26 => 4,
            $info === 27 => 8,
            default => throw new \RuntimeException('cbor_invalid_length'),
        };
        if ($bytes === 0) {
            return $info;
        }
        if ($offset + $bytes > strlen($bin)) {
            throw new \RuntimeException('cbor_truncated');
        }
        $value = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $value = ($value << 8) | ord($bin[$offset++]);
        }
        return $value;
    }
}
