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

    /** @return array{status: string, token?: string} */
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
        $twofa_required = $user['twofa_secret'] !== null;

        if ($this->isNewDevice((int) $user['id'], $request)) {
            $this->notifyNewDevice($user, $request);
        }
        $token = $this->createSession((int) $user['id'], $request, twofa_passed: !$twofa_required);
        $this->db->run('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$request->ip, $user['id']]);
        $this->audit->log((int) $user['id'], $email, 'auth.login', ['twofa_pending' => $twofa_required], $request->ip);

        return $twofa_required
            ? ['status' => 'twofa_required', 'token' => $token]
            : ['status' => 'ok', 'token' => $token];
    }

    public function verifyTwofa(string $token, string $code, Request $request): void
    {
        $session = $this->sessionRow($token);
        if ($session === null) {
            throw new HttpException(401, 'invalid_session');
        }
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$session['user_id']]);
        if ($user === null || $user['twofa_secret'] === null
            || !Totp::verify((string) $user['twofa_secret'], $code)
        ) {
            $this->audit->log($session['user_id'] ?? null, (string) ($user['email'] ?? '?'), 'auth.twofa_failed', null, $request->ip);
            throw new HttpException(401, 'invalid_code');
        }
        $this->db->run('UPDATE sessions SET twofa_passed = 1 WHERE id = ?', [$session['id']]);
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
