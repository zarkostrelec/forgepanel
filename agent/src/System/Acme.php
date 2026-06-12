<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Vlastiti minimalni ACME klijent (RFC 8555) — http-01 challenge.
 * Let's Encrypt primarni; ZeroSSL fallback ide kroz drugi directory URL.
 * Bez vanjskih ovisnosti: openssl ekstenzija + cURL.
 */
final class Acme
{
    public const LE_DIRECTORY = 'https://acme-v02.api.letsencrypt.org/directory';
    public const CHALLENGE_ROOT = '/var/www/forgepanel-acme/.well-known/acme-challenge';
    public const ACCOUNT_KEY = '/etc/forgepanel/ssl/account.key';

    /** @var array<string, string> */
    private array $directory = [];
    private ?string $nonce = null;
    private ?string $kid = null;
    private readonly \OpenSSLAsymmetricKey $account_key;

    public function __construct(private readonly string $directory_url = self::LE_DIRECTORY)
    {
        $this->account_key = $this->loadOrCreateAccountKey();
    }

    /**
     * Izdaje certifikat za hostname(e); vraća ['fullchain' => pem, 'privkey' => pem].
     * @param list<string> $hostnames
     * @return array{fullchain: string, privkey: string}
     */
    public function issue(array $hostnames, string $contact_email, ?\Closure $log = null): array
    {
        $log ??= static fn (string $s) => null;
        $this->bootstrap($contact_email);

        $log('ACME: kreiram order za ' . implode(', ', $hostnames));
        $order = $this->signedRequest($this->directory['newOrder'], [
            'identifiers' => array_map(static fn (string $h) => ['type' => 'dns', 'value' => $h], $hostnames),
        ]);
        $order_url = $order['headers']['location'];

        foreach ($order['body']['authorizations'] as $authz_url) {
            $this->satisfyAuthorization($authz_url, $log);
        }

        $log('ACME: finaliziram order (CSR)');
        $domain_key = $this->newRsaKey();
        $csr = $this->makeCsr($hostnames, $domain_key);
        $this->signedRequest($order['body']['finalize'], ['csr' => $csr]);

        $cert_url = $this->pollOrder($order_url);
        $fullchain = $this->signedRequest($cert_url, null, raw: true)['raw'];
        $log('ACME: certifikat preuzet');

        openssl_pkey_export($domain_key, $privkey_pem);
        return ['fullchain' => $fullchain, 'privkey' => $privkey_pem];
    }

    private function satisfyAuthorization(string $authz_url, \Closure $log): void
    {
        $authz = $this->signedRequest($authz_url, null)['body'];
        if ($authz['status'] === 'valid') {
            return;
        }
        $challenge = null;
        foreach ($authz['challenges'] as $c) {
            if ($c['type'] === 'http-01') {
                $challenge = $c;
                break;
            }
        }
        if ($challenge === null) {
            throw new \RuntimeException('Nema http-01 challengea za ' . $authz['identifier']['value']);
        }

        $key_authz = $challenge['token'] . '.' . $this->thumbprint();
        if (!is_dir(self::CHALLENGE_ROOT)) {
            mkdir(self::CHALLENGE_ROOT, 0o755, true);
        }
        $token_file = self::CHALLENGE_ROOT . '/' . basename($challenge['token']);
        file_put_contents($token_file, $key_authz);

        try {
            $log('ACME: http-01 challenge za ' . $authz['identifier']['value']);
            $this->signedRequest($challenge['url'], new \stdClass());
            $this->pollUntil($authz_url, ['valid'], ['invalid'], 'autorizacija');
        } finally {
            @unlink($token_file);
        }
    }

    private function pollOrder(string $order_url): string
    {
        $body = $this->pollUntil($order_url, ['valid'], ['invalid'], 'order');
        return $body['certificate'];
    }

    /** @param list<string> $ok @param list<string> $fail @return array<string, mixed> */
    private function pollUntil(string $url, array $ok, array $fail, string $what): array
    {
        for ($i = 0; $i < 30; $i++) {
            $body = $this->signedRequest($url, null)['body'];
            if (in_array($body['status'], $ok, true)) {
                return $body;
            }
            if (in_array($body['status'], $fail, true)) {
                throw new \RuntimeException("ACME $what neuspješan: " . json_encode($body['error'] ?? $body['status']));
            }
            sleep(2);
        }
        throw new \RuntimeException("ACME $what timeout");
    }

    private function bootstrap(string $contact_email): void
    {
        if ($this->directory !== []) {
            return;
        }
        $this->directory = $this->http('GET', $this->directory_url)['body'];
        $account = $this->signedRequest($this->directory['newAccount'], [
            'termsOfServiceAgreed' => true,
            'contact' => ['mailto:' . $contact_email],
        ]);
        $this->kid = $account['headers']['location'];
    }

    /**
     * @param array<string, mixed>|\stdClass|null $payload  null = POST-as-GET
     * @return array{body: array<string, mixed>, headers: array<string, string>, raw: string}
     */
    private function signedRequest(string $url, array|\stdClass|null $payload, bool $raw = false): array
    {
        $protected = ['alg' => 'RS256', 'nonce' => $this->getNonce(), 'url' => $url];
        if ($this->kid !== null) {
            $protected['kid'] = $this->kid;
        } else {
            $protected['jwk'] = $this->jwk();
        }

        $protected64 = self::b64($this->json($protected));
        $payload64 = $payload === null ? '' : self::b64($this->json($payload));
        openssl_sign("$protected64.$payload64", $signature, $this->account_key, OPENSSL_ALGO_SHA256);

        $response = $this->http('POST', $url, $this->json([
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => self::b64($signature),
        ]), $raw);

        $this->nonce = $response['headers']['replay-nonce'] ?? null;
        if ($response['status'] >= 400) {
            throw new \RuntimeException("ACME greška {$response['status']}: {$response['raw']}");
        }
        return $response;
    }

    /** @return array{status: int, body: array<string, mixed>, headers: array<string, string>, raw: string} */
    private function http(string $method, string $url, ?string $body = null, bool $raw = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/jose+json'],
            CURLOPT_POSTFIELDS => $body,
            // ACME nad rootom: obavezna TLS verifikacija + samo https (anti-MITM na izdavanje certa)
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($ch);
        if (!is_string($response)) {
            throw new \RuntimeException('ACME HTTP greška: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = [];
        foreach (explode("\r\n", substr($response, 0, $header_size)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        $raw_body = substr($response, $header_size);
        return [
            'status' => $status,
            'body' => $raw ? [] : (json_decode($raw_body, true) ?? []),
            'headers' => $headers,
            'raw' => $raw_body,
        ];
    }

    private function getNonce(): string
    {
        if ($this->nonce === null) {
            $this->nonce = $this->http('HEAD', $this->directory['newNonce'])['headers']['replay-nonce'];
        }
        $nonce = $this->nonce;
        $this->nonce = null;
        return $nonce;
    }

    /** @return array<string, string> */
    private function jwk(): array
    {
        $details = openssl_pkey_get_details($this->account_key);
        return [
            'e' => self::b64($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => self::b64($details['rsa']['n']),
        ];
    }

    private function thumbprint(): string
    {
        return self::b64(hash('sha256', $this->json($this->jwk()), true));
    }

    /** @param list<string> $hostnames */
    private function makeCsr(array $hostnames, \OpenSSLAsymmetricKey $key): string
    {
        $san = implode(',', array_map(static fn (string $h) => "DNS:$h", $hostnames));
        $config = tempnam(sys_get_temp_dir(), 'fpcsr');
        file_put_contents($config, "[req]\ndistinguished_name=dn\nreq_extensions=ext\n[dn]\n[ext]\nsubjectAltName=$san\n");
        try {
            $csr = openssl_csr_new(['CN' => $hostnames[0]], $key, ['config' => $config, 'digest_alg' => 'sha256']);
            if ($csr === false) {
                throw new \RuntimeException('CSR generiranje nije uspjelo');
            }
            openssl_csr_export($csr, $pem);
        } finally {
            unlink($config);
        }
        preg_match('/-+BEGIN[^-]+-+(.+?)-+END/s', $pem, $m);
        return self::b64(base64_decode(str_replace(["\n", "\r"], '', $m[1])));
    }

    private function loadOrCreateAccountKey(): \OpenSSLAsymmetricKey
    {
        if (is_file(self::ACCOUNT_KEY)) {
            $key = openssl_pkey_get_private((string) file_get_contents(self::ACCOUNT_KEY));
            if ($key !== false) {
                return $key;
            }
        }
        $key = $this->newRsaKey();
        openssl_pkey_export($key, $pem);
        $dir = dirname(self::ACCOUNT_KEY);
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        file_put_contents(self::ACCOUNT_KEY, $pem);
        chmod(self::ACCOUNT_KEY, 0o600);
        return $key;
    }

    private function newRsaKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new \RuntimeException('Generiranje RSA ključa nije uspjelo');
        }
        return $key;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
