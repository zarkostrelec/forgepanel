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
 * Licenciranje: master izdaje/suspendira licence (prodaja), nodovi se aktiviraju i
 * periodički re-validiraju. Token je ed25519-potpisan istim ključem kao distribucija;
 * node ga verificira javnim ključem mastera. Offline grace = zadnji potpisani token.
 */
final class LicensingController extends Controller
{
    private const TIERS = ['standard', 'pro', 'enterprise'];
    private const STATUSES = ['active', 'suspended', 'revoked'];
    private const TOKEN_KEYS = ['key', 'tier', 'status', 'expires_at', 'fingerprint', 'issued_at'];
    private const TRIAL_DAYS = 7;

    public function register(Router $router): void
    {
        // MASTER (admin) — upravljanje licencama
        $router->add('GET', '/api/v1/licenses', $this->index(...));
        $router->add('GET', '/api/v1/licenses/nodes', $this->nodes(...));
        $router->add('POST', '/api/v1/licenses', $this->create(...));
        $router->add('PUT', '/api/v1/licenses/{id}', $this->update(...));
        $router->add('DELETE', '/api/v1/licenses/{id}', $this->remove(...));
        $router->add('GET', '/api/v1/license/tiers', $this->tiersGet(...));
        $router->add('PUT', '/api/v1/license/tiers', $this->tiersSet(...));
        // PUBLIC — node ↔ master
        $router->add('POST', '/api/v1/license/activate', $this->activate(...));
        $router->add('POST', '/api/v1/license/check', $this->validateKey(...));
        $router->add('POST', '/api/v1/license/trial', $this->trial(...));
        // NODE (admin) — vlastiti status / aktivacija
        $router->add('GET', '/api/v1/license', $this->nodeStatus(...));
        $router->add('PUT', '/api/v1/license', $this->nodeConfigure(...));
    }

    /** Konfigurabilna lista tierova (settings.license_tiers) ili default. @return list<string> */
    private function tiers(): array
    {
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = 'license_tiers'");
        if ($row !== null) {
            $v = json_decode((string) $row['value'], true);
            if (is_array($v) && $v !== []) {
                return array_values(array_filter(array_map('strval', $v)));
            }
        }
        return self::TIERS;
    }

    private function tiersGet(Request $request): never
    {
        $this->adminCtx($request);
        Response::ok(['tiers' => $this->tiers()]);
    }

    /** Master uređuje dostupne tier opcije (kategorije licenci). */
    private function tiersSet(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        $in = $request->body['tiers'] ?? null;
        if (!is_array($in) || $in === [] || count($in) > 20) {
            throw new HttpException(422, 'invalid_tiers');
        }
        $clean = [];
        foreach ($in as $t) {
            $t = strtolower(trim((string) $t));
            if ($t === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9][a-z0-9 _-]{0,31}$/', $t)) {
                throw new HttpException(422, 'invalid_tier');
            }
            $clean[$t] = true; // dedup
        }
        $clean = array_keys($clean);
        if ($clean === []) {
            throw new HttpException(422, 'invalid_tiers');
        }
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES ('license_tiers', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [json_encode(array_values($clean), JSON_UNESCAPED_SLASHES)]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'license.tiers_update', ['tiers' => $clean], $request->ip);
        Response::ok(['tiers' => $clean]);
    }

    // ───────────── MASTER ─────────────

    private function index(Request $request): never
    {
        $this->adminCtx($request);
        Response::ok($this->app->db->all(
            "SELECT l.*, (SELECT COUNT(*) FROM license_activations a WHERE a.license_id = l.id) AS activations
             FROM licenses l ORDER BY l.created_at DESC LIMIT 500"
        ));
    }

    /** Svi povezani paneli (nodovi): aktivirane licence + trialovi, sortirano po zadnjem kontaktu. */
    private function nodes(Request $request): never
    {
        $this->adminCtx($request);
        $out = [];
        foreach ($this->app->db->all(
            "SELECT a.fingerprint, a.version, a.ip, a.last_seen, l.license_key, l.tier, l.status, l.expires_at
             FROM license_activations a JOIN licenses l ON l.id = a.license_id
             ORDER BY a.last_seen DESC LIMIT 1000"
        ) as $r) {
            $status = (string) $r['status'];
            if ($status === 'active' && $r['expires_at'] !== null && strtotime((string) $r['expires_at']) < time()) {
                $status = 'expired';
            }
            $out[] = [
                'type' => 'license', 'fingerprint' => $r['fingerprint'], 'version' => $r['version'],
                'ip' => $r['ip'], 'last_seen' => $r['last_seen'], 'tier' => $r['tier'],
                'status' => $status, 'expires_at' => $r['expires_at'], 'license_key' => $r['license_key'],
            ];
        }
        foreach ($this->app->db->all('SELECT fingerprint, version, ip, first_seen, last_seen FROM trials ORDER BY last_seen DESC LIMIT 1000') as $r) {
            $exp = (int) strtotime((string) $r['first_seen']) + self::TRIAL_DAYS * 86400;
            $out[] = [
                'type' => 'trial', 'fingerprint' => $r['fingerprint'], 'version' => $r['version'],
                'ip' => $r['ip'], 'last_seen' => $r['last_seen'], 'tier' => 'trial',
                'status' => time() < $exp ? 'trial' : 'expired', 'expires_at' => date('Y-m-d H:i:s', $exp),
            ];
        }
        usort($out, static fn ($a, $b) => strcmp((string) $b['last_seen'], (string) $a['last_seen']));
        Response::ok($out);
    }

    private function create(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        $tiers = $this->tiers();
        $tier = $request->str('tier') ?? ($tiers[0] ?? 'standard');
        if (!in_array($tier, $tiers, true)) {
            throw new HttpException(422, 'invalid_tier');
        }
        $key = $this->genKey();
        $this->app->db->run(
            "INSERT INTO licenses (license_key, tier, customer, expires_at, notes) VALUES (?, ?, ?, ?, ?)",
            [$key, $tier, mb_substr(trim($request->str('customer') ?? ''), 0, 128) ?: null,
                $this->parseExpiry($request->str('expires_at')), mb_substr(trim($request->str('notes') ?? ''), 0, 2000) ?: null]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'license.create', ['key' => $key, 'tier' => $tier], $request->ip);
        Response::ok(['id' => $this->app->db->lastId(), 'license_key' => $key], 201);
    }

    private function update(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        $lic = $this->licOr404((int) $request->param('id'));
        $fields = [];
        $args = [];
        $status = $request->str('status');
        if ($status !== null) {
            if (!in_array($status, self::STATUSES, true)) {
                throw new HttpException(422, 'invalid_status');
            }
            $fields[] = 'status = ?';
            $args[] = $status;
        }
        if (array_key_exists('expires_at', $request->body)) {
            $fields[] = 'expires_at = ?';
            $args[] = $this->parseExpiry($request->str('expires_at'));
        }
        if ($request->str('tier') !== null) {
            if (!in_array($request->str('tier'), $this->tiers(), true)) {
                throw new HttpException(422, 'invalid_tier');
            }
            $fields[] = 'tier = ?';
            $args[] = $request->str('tier');
        }
        if ($fields === []) {
            throw new HttpException(422, 'nothing_to_update');
        }
        $args[] = $lic['id'];
        $this->app->db->run('UPDATE licenses SET ' . implode(', ', $fields) . ' WHERE id = ?', $args);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'license.update', ['id' => (int) $lic['id'], 'status' => $status], $request->ip);
        Response::ok();
    }

    private function remove(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        $lic = $this->licOr404((int) $request->param('id'));
        $this->app->db->run('DELETE FROM licenses WHERE id = ?', [$lic['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'license.delete', ['id' => (int) $lic['id']], $request->ip);
        Response::ok();
    }

    // ───────────── PUBLIC (node → master) ─────────────

    private function activate(Request $request): never
    {
        $lic = $this->lookupKey($request);
        $fp = $this->fp($request);
        if ($fp !== '') {
            $this->app->db->run(
                "INSERT INTO license_activations (license_id, fingerprint, version, ip) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE version = VALUES(version), ip = VALUES(ip), last_seen = NOW()",
                [$lic['id'], $fp, mb_substr($request->str('version') ?? '', 0, 32), $request->ip]
            );
        }
        Response::ok($this->signedToken($lic, $fp));
    }

    private function validateKey(Request $request): never
    {
        $lic = $this->lookupKey($request);
        $fp = $this->fp($request);
        if ($fp !== '') {
            $this->app->db->run('UPDATE license_activations SET last_seen = NOW() WHERE license_id = ? AND fingerprint = ?', [$lic['id'], $fp]);
        }
        Response::ok($this->signedToken($lic, $fp));
    }

    /**
     * Probni period: master bilježi PRVI kontakt nodea po fingerprintu i vraća potpisani
     * token (status 'trial' do isteka, pa 'expired'). Server-side → reinstal panela ne
     * resetira trial (fingerprint je vezan na /etc/machine-id).
     */
    private function trial(Request $request): never
    {
        $fp = $this->fp($request);
        if ($fp === '') {
            throw new HttpException(422, 'fingerprint_required');
        }
        $version = mb_substr($request->str('version') ?? '', 0, 32);
        $row = $this->app->db->one('SELECT first_seen FROM trials WHERE fingerprint = ?', [$fp]);
        if ($row === null) {
            $this->app->db->run('INSERT INTO trials (fingerprint, version, ip) VALUES (?, ?, ?)', [$fp, $version, $request->ip]);
            $first = time();
        } else {
            $first = (int) strtotime((string) $row['first_seen']);
            $this->app->db->run('UPDATE trials SET last_seen = NOW(), version = ?, ip = ? WHERE fingerprint = ?', [$version, $request->ip, $fp]);
        }
        $expires_ts = $first + self::TRIAL_DAYS * 86400;
        $synthetic = [
            'license_key' => 'TRIAL',
            'tier' => 'trial',
            'status' => time() < $expires_ts ? 'trial' : 'expired',
            'expires_at' => date('Y-m-d H:i:s', $expires_ts),
        ];
        Response::ok($this->signedToken($synthetic, $fp));
    }

    // ───────────── NODE (admin) ─────────────

    private function nodeStatus(Request $request): never
    {
        $this->adminCtx($request);
        $key = $this->setting('license_key', '');
        Response::ok([
            'configured'  => $key !== '',
            'license_key' => $key,
            'status'      => $this->setting('license_status', ''),
            'tier'        => $this->setting('license_tier', ''),
            'expires_at'  => $this->setting('license_expires', ''),
            'fingerprint' => $this->fingerprint(),
        ]);
    }

    private function nodeConfigure(Request $request): never
    {
        $ctx = $this->adminCtx($request);
        $key = trim($request->str('license_key') ?? '');
        if ($key === '') { // odspoji licencu
            foreach (['license_key', 'license_status', 'license_tier', 'license_expires', 'license_token'] as $k) {
                $this->store($k, '');
            }
            Response::ok(['status' => '']);
        }
        $server = rtrim($this->setting('update_server', ''), '/');
        $pub = $this->setting('update_pubkey', '');
        if ($server === '' || $pub === '') {
            throw new HttpException(409, 'update_not_configured');
        }
        $token = $this->callMaster($server, '/api/v1/license/activate', [
            'key' => $key, 'fingerprint' => $this->fingerprint(), 'version' => $this->setting('panel_version', '1.0.0'),
        ]);
        if (!$this->verifyToken($token, $pub)) {
            throw new HttpException(422, 'token_invalid');
        }
        $p = $token['license'];
        $this->store('license_key', $key);
        $this->store('license_status', (string) $p['status']);
        $this->store('license_tier', (string) $p['tier']);
        $this->store('license_expires', (string) ($p['expires_at'] ?? ''));
        $this->store('license_token', json_encode($token, JSON_UNESCAPED_SLASHES));
        $this->app->audit->log($ctx->user_id, $ctx->email, 'license.activate', ['status' => $p['status']], $request->ip);
        Response::ok(['status' => $p['status'], 'tier' => $p['tier'], 'expires_at' => $p['expires_at'] ?? null]);
    }

    // ───────────── helpers ─────────────

    /** @param array<string,mixed> $lic @return array<string,mixed> */
    private function signedToken(array $lic, string $fp): array
    {
        $status = (string) $lic['status'];
        if ($status === 'active' && $lic['expires_at'] !== null && strtotime((string) $lic['expires_at']) < time()) {
            $status = 'expired';
        }
        $payload = [
            'key' => $lic['license_key'], 'tier' => $lic['tier'], 'status' => $status,
            'expires_at' => (string) ($lic['expires_at'] ?? ''), 'fingerprint' => $fp, 'issued_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $enc = $this->settingRaw('dist_signing_key') ?? throw new HttpException(409, 'no_signing_key');
        $secret = base64_decode((new Crypto($this->app->config))->decrypt($enc));
        return ['license' => $payload, 'signature' => base64_encode(sodium_crypto_sign_detached($this->canonical($payload), $secret))];
    }

    /** @param array<string,mixed> $token */
    private function verifyToken(array $token, string $pub): bool
    {
        if (!isset($token['license'], $token['signature']) || !is_array($token['license'])) {
            return false;
        }
        return sodium_crypto_sign_verify_detached(base64_decode((string) $token['signature']), $this->canonical($token['license']), base64_decode($pub));
    }

    /** @param array<string,mixed> $p */
    private function canonical(array $p): string
    {
        $out = [];
        foreach (self::TOKEN_KEYS as $k) {
            $out[$k] = (string) ($p[$k] ?? '');
        }
        return (string) json_encode($out, JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string,mixed> */
    private function lookupKey(Request $request): array
    {
        $key = trim($request->str('key') ?? '');
        return $this->app->db->one('SELECT * FROM licenses WHERE license_key = ?', [$key])
            ?? throw new HttpException(404, 'license_not_found');
    }

    private function fp(Request $request): string
    {
        return (string) preg_replace('/[^a-f0-9]/', '', strtolower($request->str('fingerprint') ?? ''));
    }

    /** Stabilan otisak ovog panela — vezan na /etc/machine-id (preživi reinstal panela). */
    private function fingerprint(): string
    {
        $fp = $this->setting('license_fingerprint', '');
        if ($fp !== '') {
            return $fp;
        }
        $machine = '';
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
            if (is_readable($f)) {
                $machine = trim((string) @file_get_contents($f));
                if ($machine !== '') {
                    break;
                }
            }
        }
        $fp = $machine !== '' ? hash('sha256', 'forgepanel:' . $machine) : bin2hex(random_bytes(16));
        $this->store('license_fingerprint', $fp);
        return $fp;
    }

    private function genKey(): string
    {
        $raw = strtoupper(bin2hex(random_bytes(10)));
        return 'FP-' . substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4) . '-' . substr($raw, 16, 4);
    }

    private function parseExpiry(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function callMaster(string $server, string $path, array $body): array
    {
        if (!HttpClient::isSafePublicUrl($server . '/')) {
            throw new HttpException(422, 'update_server_unsafe');
        }
        $ch = curl_init($server . $path);
        HttpClient::apply($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || ($data['ok'] ?? false) !== true || !isset($data['data'])) {
            throw new HttpException(502, is_array($data) && isset($data['error']) ? (string) $data['error'] : 'master_unreachable');
        }
        return $data['data'];
    }

    /** @return array<string,mixed> */
    private function licOr404(int $id): array
    {
        return $this->app->db->one('SELECT * FROM licenses WHERE id = ?', [$id]) ?? throw new HttpException(404, 'not_found');
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
