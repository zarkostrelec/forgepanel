<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Autentikacija: argon2id lozinke, rate limiting s exponential backoffom,
 * TOTP 2FA (obavezan za admina), sessioni vezani na IP+UA, Bearer API tokeni sa scopovima.
 */
final class Auth
{
    private const SESSION_TTL_S = 3600 * 8;
    private const MAX_FAILED = 5;
    private const WEBAUTHN_CHALLENGE_TTL_S = 300;

    public function __construct(
        private readonly Db $db,
        private readonly Audit $audit,
    ) {
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 2,
        ]);
    }

    /** @return array{status: string, token?: string, methods?: list<string>} */
    public function login(string $email, string $password, Request $request): array
    {
        $user = $this->db->one('SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ?', [$email]);

        if ($user !== null && $user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            throw new HttpException(429, 'account_locked');
        }

        if ($user === null
            || $user['status'] !== 'active'
            || !password_verify($password, (string) $user['password_hash'])
        ) {
            if ($user !== null) {
                $this->registerFailure((int) $user['id'], (int) $user['failed_logins']);
            }
            $this->audit->log($user['id'] ?? null, $email, 'auth.login_failed', null, $request->ip);
            // Identičan odgovor za nepostojeći mail i krivu lozinku — bez user enumeracije
            throw new HttpException(401, 'invalid_credentials');
        }

        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$user['id']]);

        // 2FA je obavezan za admina: admin bez secreta ulazi, ali UI forsira setup prije svega ostalog
        $methods = [];
        if ($user['twofa_secret'] !== null) {
            $methods[] = 'totp';
        }
        if ($this->userHasWebauthn((int) $user['id'])) {
            $methods[] = 'webauthn';
        }
        $twofa_required = $methods !== [];

        if ($this->isNewDevice((int) $user['id'], $request)) {
            $this->notifyNewDevice($user, $request);
        }
        $token = $this->createSession((int) $user['id'], $request, twofa_passed: !$twofa_required);
        $this->db->run('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$request->ip, $user['id']]);
        $this->audit->log((int) $user['id'], $email, 'auth.login', ['twofa_pending' => $twofa_required], $request->ip);

        return $twofa_required
            ? ['status' => 'twofa_required', 'token' => $token, 'methods' => $methods]
            : ['status' => 'ok', 'token' => $token];
    }

    public function verifyTwofa(string $token, string $code, Request $request): void
    {
        $session = $this->sessionRow($token);
        if ($session === null) {
            throw new HttpException(401, 'invalid_session');
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$session['user_id']]);
        if ($user === null) {
            throw new HttpException(401, 'invalid_session');
        }
        // Brute-force zaštita i na drugom faktoru: isti lockout kao kod lozinke.
        $this->assertNotLocked($user);
        if ($user['twofa_secret'] === null || !Totp::verify((string) $user['twofa_secret'], $code)) {
            $this->registerFailure((int) $user['id'], (int) $user['failed_logins']);
            $this->audit->log($session['user_id'] ?? null, (string) ($user['email'] ?? '?'), 'auth.twofa_failed', null, $request->ip);
            throw new HttpException(401, 'invalid_code');
        }
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
        $this->db->run('UPDATE sessions SET twofa_passed = 1 WHERE id = ?', [$session['id']]);
    }

    public function userHasWebauthn(int $user_id): bool
    {
        return $this->db->one('SELECT 1 FROM webauthn_credentials WHERE user_id = ? LIMIT 1', [$user_id]) !== null;
    }

    /**
     * Generira login challenge za WebAuthn drugi faktor (pending session, twofa još nije prošao).
     *
     * @return array{challenge: string, rp_id: string, allow: list<string>}
     */
    public function webauthnLoginOptions(string $token, string $rp_id): array
    {
        $session = $this->sessionRow($token) ?? throw new HttpException(401, 'invalid_session');
        $credentials = $this->db->all(
            'SELECT credential_id FROM webauthn_credentials WHERE user_id = ?',
            [$session['user_id']]
        );
        if ($credentials === []) {
            throw new HttpException(422, 'webauthn_not_configured');
        }
        $challenge = WebAuthn::generateChallenge();
        $this->db->run(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['webauthn_login_' . $session['id'], json_encode(['challenge' => $challenge, 'ts' => time()])]
        );
        return [
            'challenge' => $challenge,
            'rp_id' => $rp_id,
            'allow' => array_column($credentials, 'credential_id'),
        ];
    }

    /** @param array<string, mixed> $response */
    public function verifyWebauthnLogin(string $token, array $response, string $rp_id, string $origin, Request $request): void
    {
        $session = $this->sessionRow($token) ?? throw new HttpException(401, 'invalid_session');
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$session['user_id']])
            ?? throw new HttpException(401, 'invalid_session');
        $email = (string) ($user['email'] ?? '?');
        // Brute-force zaštita i na WebAuthn drugom faktoru.
        $this->assertNotLocked($user);

        $challenge = $this->consumeChallenge('webauthn_login_' . $session['id']);
        $credential = $this->db->one(
            'SELECT * FROM webauthn_credentials WHERE credential_id = ? AND user_id = ?',
            [(string) ($response['credential_id'] ?? ''), $session['user_id']]
        );

        try {
            if ($challenge === null || $credential === null) {
                throw new \RuntimeException('unknown_credential');
            }
            $new_count = WebAuthn::verifyAssertion(
                $response,
                $challenge,
                $rp_id,
                $origin,
                (string) $credential['public_key'],
                (int) $credential['alg'],
                (int) $credential['sign_count'],
            );
        } catch (\RuntimeException $e) {
            $this->registerFailure((int) $session['user_id'], (int) $user['failed_logins']);
            $this->audit->log((int) $session['user_id'], $email, 'auth.webauthn_failed', ['reason' => $e->getMessage()], $request->ip);
            throw new HttpException(401, 'invalid_assertion');
        }

        $this->db->run(
            'UPDATE webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?',
            [$new_count, $credential['id']]
        );
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
        $this->db->run('UPDATE sessions SET twofa_passed = 1 WHERE id = ?', [$session['id']]);
        $this->audit->log((int) $session['user_id'], $email, 'auth.webauthn_ok', ['key' => $credential['label']], $request->ip);
    }

    /** Dohvati i obriši spremljeni challenge (jednokratan, TTL 5 min). */
    public function consumeChallenge(string $key): ?string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE `key` = ?', [$key]);
        if ($row === null) {
            return null;
        }
        $this->db->run('DELETE FROM settings WHERE `key` = ?', [$key]);
        $data = json_decode((string) $row['value'], true);
        if (!is_array($data) || !is_string($data['challenge'] ?? null)
            || time() - (int) ($data['ts'] ?? 0) > self::WEBAUTHN_CHALLENGE_TTL_S
        ) {
            return null;
        }
        return $data['challenge'];
    }

    public function logout(string $token): void
    {
        $this->db->run('DELETE FROM sessions WHERE id = ?', [hash('sha256', $token)]);
    }

    public function authenticate(Request $request): ?AuthContext
    {
        if ($request->bearer_token === null) {
            return null;
        }
        // Session token (UI) ili API token — oba kroz Authorization: Bearer
        return $this->fromSession($request) ?? $this->fromApiToken($request);
    }

    public function requireAuth(Request $request): AuthContext
    {
        return $this->authenticate($request) ?? throw new HttpException(401, 'unauthenticated');
    }

    private function fromSession(Request $request): ?AuthContext
    {
        $session = $this->sessionRow((string) $request->bearer_token);
        if ($session === null || (int) $session['twofa_passed'] !== 1) {
            return null;
        }
        // Session binding IP+UA
        if ($session['ip'] !== $request->ip
            || !hash_equals((string) $session['user_agent_hash'], hash('sha256', $request->user_agent))
        ) {
            return null;
        }
        return $this->context((int) $session['user_id'], ['*']);
    }

    private function fromApiToken(Request $request): ?AuthContext
    {
        $hash = hash('sha256', (string) $request->bearer_token);
        $token = $this->db->one(
            'SELECT * FROM api_tokens WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > NOW())',
            [$hash]
        );
        if ($token === null) {
            return null;
        }
        $this->db->run('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?', [$token['id']]);
        $scopes = json_decode((string) $token['scopes'], true);
        return $this->context((int) $token['user_id'], is_array($scopes) ? $scopes : []);
    }

    /** @param list<string> $scopes */
    private function context(int $user_id, array $scopes): ?AuthContext
    {
        $user = $this->db->one(
            'SELECT u.id, u.email, u.status, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?',
            [$user_id]
        );
        if ($user === null || $user['status'] !== 'active') {
            return null;
        }
        $subscription_ids = array_map(
            intval(...),
            array_column($this->db->all('SELECT id FROM subscriptions WHERE user_id = ?', [$user_id]), 'id')
        );
        return new AuthContext($user_id, (string) $user['email'], (string) $user['role'], $scopes, $subscription_ids, $this->db);
    }

    private function createSession(int $user_id, Request $request, bool $twofa_passed): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run(
            'INSERT INTO sessions (id, user_id, ip, user_agent_hash, twofa_passed, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
            [hash('sha256', $token), $user_id, $request->ip, hash('sha256', $request->user_agent), (int) $twofa_passed, self::SESSION_TTL_S]
        );
        return $token;
    }

    /** @return array<string, mixed>|null */
    private function sessionRow(string $token): ?array
    {
        return $this->db->one(
            'SELECT * FROM sessions WHERE id = ? AND expires_at > NOW()',
            [hash('sha256', $token)]
        );
    }

    /** @param array<string, mixed> $user — baca 429 ako je račun trenutno zaključan. */
    private function assertNotLocked(array $user): void
    {
        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            throw new HttpException(429, 'account_locked');
        }
    }

    private function registerFailure(int $user_id, int $failed_so_far): void
    {
        $failed = $failed_so_far + 1;
        // 5 pokušaja → exponential backoff: 2^(n-5) minuta locka
        $lock_minutes = $failed >= self::MAX_FAILED ? 2 ** ($failed - self::MAX_FAILED + 1) : 0;
        $this->db->run(
            'UPDATE users SET failed_logins = ?, locked_until = IF(? > 0, DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL) WHERE id = ?',
            [$failed, $lock_minutes, $lock_minutes, $user_id]
        );
    }

    private function isNewDevice(int $user_id, Request $request): bool
    {
        return $this->db->one(
            'SELECT 1 FROM sessions WHERE user_id = ? AND ip = ? AND user_agent_hash = ? LIMIT 1',
            [$user_id, $request->ip, hash('sha256', $request->user_agent)]
        ) === null;
    }

    /** @param array<string, mixed> $user */
    private function notifyNewDevice(array $user, Request $request): void
    {
        $this->db->run(
            'INSERT INTO notifications (user_id, severity, title, body) VALUES (?, ?, ?, ?)',
            [
                $user['id'],
                'warning',
                'Prijava s novog uređaja',
                sprintf('Nova prijava s IP adrese %s (%s).', $request->ip, mb_substr($request->user_agent, 0, 120)),
            ]
        );
    }
}
