<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Crypto;
use ForgePanel\Web\Core\HttpClient;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Distribucija panel updatea (mothership). Master objavljuje POTPISANE release-ove
 * (ed25519); nodovi povlače manifest, verificiraju potpis javnim ključem mastera i
 * tek onda primjenjuju (panel.self_update). Bez verifikacije = supply-chain backdoor.
 */
final class DistributionController extends Controller
{
    private const CHANNELS = ['stable', 'beta'];

    public function register(Router $router): void
    {
        // MASTER (admin)
        $router->add('GET', '/api/v1/distribution/keys', $this->keys(...));
        $router->add('POST', '/api/v1/distribution/keygen', $this->keygen(...));
        $router->add('GET', '/api/v1/distribution/releases', $this->releases(...));
        $router->add('POST', '/api/v1/distribution/releases', $this->publish(...));
        // PUBLIC manifest — nodovi povlače; integritet osigurava potpis, ne auth
        $router->add('GET', '/api/v1/distribution/manifest', $this->manifest(...));
        // NODE (admin)
        $router->add('GET', '/api/v1/distribution/node', $this->nodeConfig(...));
        $router->add('PUT', '/api/v1/distribution/node', $this->saveNode(...));
        $router->add('GET', '/api/v1/distribution/check', $this->check(...));
        $router->add('POST', '/api/v1/distribution/apply', $this->apply(...));
    }

    // ───────────────────────── MASTER ─────────────────────────

    private function keys(Request $request): never
    {
        $this->adminCtx($request);
        Response::ok([
            'has_key'    => $this->settingRaw('dist_signing_key') !== null,
            'public_key' => $this->settingRaw('dist_public_key'),
            'version'    => $this->setting('panel_version', '1.0.0'),
        ]);
    }

    private function keygen(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        if ($this->settingRaw('dist_signing_key') !== null && ($request->body['force'] ?? false) !== true) {
            throw new HttpException(409, 'key_exists');
        }
        $pair = sodium_crypto_sign_keypair();
        $this->store('dist_signing_key', (new Crypto($this->app->config))->encrypt(base64_encode(sodium_crypto_sign_secretkey($pair))));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->store('dist_public_key', $public);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'distribution.keygen', null, $request->ip);
        Response::ok(['public_key' => $public], 201);
    }

    private function publish(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        $enc = $this->settingRaw('dist_signing_key') ?? throw new HttpException(409, 'no_signing_key');
        $secret = base64_decode((new Crypto($this->app->config))->decrypt($enc));

        $version = trim($request->str('version') ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+([.-][A-Za-z0-9]+)?$/', $version)) {
            throw new HttpException(422, 'invalid_version');
        }
        $channel = $request->str('channel') ?? 'stable';
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new HttpException(422, 'invalid_channel');
        }
        $url = trim($request->str('package_url') ?? '');
        if (!preg_match('#^https://[\w./%:+-]{1,500}$#', $url)) {
            throw new HttpException(422, 'invalid_url');
        }
        $sha = strtolower(trim($request->str('sha256') ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new HttpException(422, 'invalid_sha256');
        }
        $notes = mb_substr(trim($request->str('notes') ?? ''), 0, 4000);
        $min = trim($request->str('min_version') ?? '');

        $manifest = [
            'version' => $version, 'channel' => $channel, 'package_url' => $url, 'sha256' => $sha,
            'min_version' => $min, 'published_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $sig = base64_encode(sodium_crypto_sign_detached(self::canonical($manifest), $secret));

        $this->app->db->run(
            "INSERT INTO releases (version, channel, package_url, sha256, signature, notes, min_version, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE package_url = VALUES(package_url), sha256 = VALUES(sha256),
                signature = VALUES(signature), notes = VALUES(notes), min_version = VALUES(min_version),
                published_at = VALUES(published_at)",
            [$version, $channel, $url, $sha, $sig, $notes ?: null, $min ?: null, $manifest['published_at']]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'distribution.publish', ['version' => $version, 'channel' => $channel], $request->ip);
        Response::ok(['version' => $version, 'channel' => $channel], 201);
    }

    private function releases(Request $request): never
    {
        $this->adminCtx($request);
        Response::ok($this->app->db->all(
            'SELECT version, channel, package_url, sha256, notes, min_version, published_at FROM releases ORDER BY published_at DESC LIMIT 100'
        ));
    }

    // ───────────────────────── PUBLIC manifest ─────────────────────────

    private function manifest(Request $request): never
    {
        $channel = (string) ($_GET['channel'] ?? 'stable');
        if (!in_array($channel, self::CHANNELS, true)) {
            $channel = 'stable';
        }
        $row = $this->app->db->one(
            'SELECT version, channel, package_url, sha256, signature, notes, min_version, published_at
             FROM releases WHERE channel = ? ORDER BY published_at DESC LIMIT 1',
            [$channel]
        ) ?? throw new HttpException(404, 'no_release');

        Response::ok([
            'manifest' => [
                'version' => $row['version'], 'channel' => $row['channel'], 'package_url' => $row['package_url'],
                'sha256' => $row['sha256'], 'min_version' => (string) ($row['min_version'] ?? ''), 'published_at' => $row['published_at'],
            ],
            'signature' => $row['signature'],
            'notes' => $row['notes'],
        ]);
    }

    // ───────────────────────── NODE ─────────────────────────

    private function nodeConfig(Request $request): never
    {
        $this->adminCtx($request);
        Response::ok([
            'update_server'  => $this->setting('update_server', ''),
            'update_channel' => $this->setting('update_channel', 'stable'),
            'update_pubkey'  => $this->setting('update_pubkey', ''),
            'update_auto'    => $this->setting('panel_update_auto', 'manual'),
            'current'        => $this->setting('panel_version', '1.0.0'),
        ]);
    }

    private function saveNode(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        $server = rtrim(trim($request->str('update_server') ?? ''), '/');
        if ($server !== '' && !preg_match('#^https://[\w.:-]{1,255}$#', $server)) {
            throw new HttpException(422, 'invalid_update_server');
        }
        $channel = $request->str('update_channel') ?? 'stable';
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new HttpException(422, 'invalid_channel');
        }
        $pubkey = trim($request->str('update_pubkey') ?? '');
        if ($pubkey !== '' && (base64_decode($pubkey, true) === false || strlen((string) base64_decode($pubkey)) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)) {
            throw new HttpException(422, 'invalid_pubkey');
        }
        $auto = ($request->str('update_auto') ?? 'manual') === 'auto' ? 'auto' : 'manual';
        $this->store('update_server', $server);
        $this->store('update_channel', $channel);
        $this->store('update_pubkey', $pubkey);
        $this->store('panel_update_auto', $auto);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'distribution.node_config', ['server' => $server, 'auto' => $auto], $request->ip);
        Response::ok();
    }

    private function check(Request $request): never
    {
        $this->adminCtx($request);
        $server = rtrim($this->setting('update_server', ''), '/');
        $pub = $this->setting('update_pubkey', '');
        $channel = $this->setting('update_channel', 'stable');
        $current = $this->setting('panel_version', '1.0.0');
        if ($server === '' || $pub === '') {
            Response::ok(['configured' => false, 'current' => $current, 'channel' => $channel]);
        }
        [$manifest, $signature, $notes] = $this->fetchManifest($server, $channel);
        if (!sodium_crypto_sign_verify_detached(base64_decode($signature), self::canonical($manifest), base64_decode($pub))) {
            throw new HttpException(422, 'signature_invalid');
        }
        Response::ok([
            'configured' => true, 'current' => $current, 'channel' => $channel,
            'latest' => $manifest['version'], 'update_available' => version_compare($manifest['version'], $current, '>'),
            'notes' => $notes, 'published_at' => $manifest['published_at'],
        ]);
    }

    private function apply(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        $server = rtrim($this->setting('update_server', ''), '/');
        $pub = $this->setting('update_pubkey', '');
        if ($server === '' || $pub === '') {
            throw new HttpException(409, 'update_not_configured');
        }
        [$manifest, $signature] = $this->fetchManifest($server, $this->setting('update_channel', 'stable'));
        if (!sodium_crypto_sign_verify_detached(base64_decode($signature), self::canonical($manifest), base64_decode($pub))) {
            throw new HttpException(422, 'signature_invalid');
        }
        if (!version_compare($manifest['version'], $this->setting('panel_version', '1.0.0'), '>')) {
            throw new HttpException(409, 'already_latest');
        }
        // Agent NEOVISNO re-verificira potpis + sha256 paketa prije bilo kakvog deploya
        $task_id = $this->app->tasks->enqueue('panel.self_update', [
            'manifest' => $manifest, 'signature' => $signature, 'pubkey' => $pub,
        ], $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'distribution.apply', ['version' => $manifest['version']], $request->ip);
        Response::ok(['task_id' => $task_id, 'version' => $manifest['version']], 202);
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @return array{0: array<string,string>, 1: string, 2: ?string} [manifest, signature, notes] */
    private function fetchManifest(string $server, string $channel): array
    {
        if (!HttpClient::isSafePublicUrl($server . '/')) {
            throw new HttpException(422, 'update_server_unsafe');
        }
        $ch = curl_init($server . '/api/v1/distribution/manifest?channel=' . rawurlencode($channel));
        HttpClient::apply($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($code !== 200 || !is_array($data) || ($data['ok'] ?? false) !== true || !isset($data['data']['manifest'])) {
            throw new HttpException(502, 'update_server_unreachable');
        }
        return [$data['data']['manifest'], (string) $data['data']['signature'], $data['data']['notes'] ?? null];
    }

    /** Kanonski (deterministički) oblik manifesta za potpis/verifikaciju. @param array<string,mixed> $m */
    private static function canonical(array $m): string
    {
        $out = [];
        foreach (['version', 'channel', 'package_url', 'sha256', 'min_version', 'published_at'] as $k) {
            $out[$k] = (string) ($m[$k] ?? '');
        }
        return (string) json_encode($out, JSON_UNESCAPED_SLASHES);
    }

    private function settingRaw(string $key): ?string
    {
        $row = $this->app->db->one('SELECT value FROM settings WHERE `key` = ?', [$key]);
        if ($row === null) {
            return null;
        }
        $v = json_decode((string) $row['value'], true);
        return is_string($v) ? $v : null;
    }

    private function store(string $key, string $value): void
    {
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [$key, json_encode($value)]
        );
    }

    private function adminCtx(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        $ctx = $this->ctx($request, 'config:read');
        $ctx->requireRole('admin');
        return $ctx;
    }
}
