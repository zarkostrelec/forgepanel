<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * ACME dns-01 provider preko Cloudflare API v4 — kreira/briše _acme-challenge TXT
 * zapis u zadanoj CF zoni. Jedini način izdavanja LE certifikata za domene iza
 * Cloudflare proxyja (orange cloud) i za wildcard certifikate.
 */
final class CloudflareDns implements Dns01Provider
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $token,
        private readonly string $zone_id,
    ) {
    }

    public function set(string $domain, string $value): string
    {
        $res = $this->request('POST', "/zones/{$this->zone_id}/dns_records", [
            'type' => 'TXT',
            'name' => '_acme-challenge.' . $domain,
            'content' => $value,
            'ttl' => 60,
        ]);
        $id = $res['result']['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('Cloudflare: TXT zapis nije kreiran.');
        }
        return $id;
    }

    public function clear(string $handle): void
    {
        // Čišćenje je best-effort — neuspjeh ne smije srušiti uspješno izdavanje.
        try {
            $this->request('DELETE', "/zones/{$this->zone_id}/dns_records/$handle");
        } catch (\Throwable) {
        }
    }

    /**
     * @param ?array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new \RuntimeException('Cloudflare: neispravan odgovor (HTTP ' . $status . ').');
        }
        if ($status >= 400 || ($decoded['success'] ?? false) !== true) {
            $msg = $decoded['errors'][0]['message'] ?? ('http_' . $status);
            throw new \RuntimeException('Cloudflare: ' . $msg);
        }
        return $decoded;
    }
}
