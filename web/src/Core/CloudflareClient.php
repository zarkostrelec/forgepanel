<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/** Cloudflare API v4 klijent — scoped token per user/subscription. */
final class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(private readonly string $token)
    {
    }

    /** @return array{ok: bool, email?: string} */
    public function verify(): array
    {
        $res = $this->request('GET', '/user/tokens/verify');
        return ['ok' => ($res['success'] ?? false) === true];
    }

    /** @return list<array{id: string, name: string}> */
    public function zones(): array
    {
        $res = $this->request('GET', '/zones?per_page=50');
        return array_map(
            static fn (array $z) => ['id' => $z['id'], 'name' => $z['name']],
            $res['result'] ?? []
        );
    }

    /** @return list<array<string, mixed>> */
    public function dnsRecords(string $zone_id): array
    {
        $res = $this->request('GET', "/zones/$zone_id/dns_records?per_page=100");
        return $res['result'] ?? [];
    }

    /** @return array<string, mixed> */
    public function createRecord(string $zone_id, string $type, string $name, string $content, bool $proxied = false, int $ttl = 1): array
    {
        return $this->request('POST', "/zones/$zone_id/dns_records", [
            'type' => $type, 'name' => $name, 'content' => $content,
            'proxied' => $proxied, 'ttl' => $ttl,
        ]);
    }

    public function setProxied(string $zone_id, string $record_id, bool $proxied): void
    {
        $this->request('PATCH', "/zones/$zone_id/dns_records/$record_id", ['proxied' => $proxied]);
    }

    public function purgeCache(string $zone_id, ?array $urls = null): void
    {
        $body = $urls === null ? ['purge_everything' => true] : ['files' => array_values($urls)];
        $this->request('POST', "/zones/$zone_id/purge_cache", $body);
    }

    /** @return list<string> CF IPv4 rangevi za real visitor IP / ufw allowlist */
    public static function ipRanges(): array
    {
        $ch = curl_init(self::BASE . '/ips');
        HttpClient::apply($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = is_string($body) ? json_decode($body, true) : null;
        return $data['result']['ipv4_cidrs'] ?? [];
    }

    /** @param ?array<string, mixed> $body @return array<string, mixed> */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::BASE . $path);
        HttpClient::apply($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
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
            throw new HttpException(502, 'cloudflare_bad_response');
        }
        if ($status >= 400 || ($decoded['success'] ?? false) !== true) {
            $msg = $decoded['errors'][0]['message'] ?? "http_$status";
            throw new HttpException(422, 'cloudflare: ' . $msg);
        }
        return $decoded;
    }
}
