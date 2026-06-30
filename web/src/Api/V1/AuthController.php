<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Auth;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;
use ForgePanel\Web\Core\Totp;
use ForgePanel\Web\Core\WebAuthn;

final class AuthController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('POST', '/api/v1/auth/login', $this->login(...));
        $router->add('POST', '/api/v1/auth/twofa', $this->twofa(...));
        $router->add('POST', '/api/v1/auth/logout', $this->logout(...));
        $router->add('GET', '/api/v1/auth/me', $this->me(...));
        $router->add('POST', '/api/v1/auth/twofa/setup', $this->twofaSetup(...));
        $router->add('POST', '/api/v1/auth/twofa/confirm', $this->twofaConfirm(...));
        $router->add('POST', '/api/v1/auth/twofa/recovery-codes', $this->recoveryCodes(...));
        $router->add('POST', '/api/v1/auth/webauthn/login/options', $this->webauthnLoginOptions(...));
        $router->add('POST', '/api/v1/auth/webauthn/login', $this->webauthnLogin(...));
        $router->add('POST', '/api/v1/auth/webauthn/register/options', $this->webauthnRegisterOptions(...));
        $router->add('POST', '/api/v1/auth/webauthn/register', $this->webauthnRegister(...));
        $router->add('GET', '/api/v1/auth/webauthn/keys', $this->webauthnKeys(...));
        $router->add('DELETE', '/api/v1/auth/webauthn/keys/{id}', $this->webauthnDelete(...));
    }

    private function login(Request $request): never
    {
        $email = $request->str('email') ?? throw new HttpException(400, 'email_required');
        $password = $request->str('password') ?? throw new HttpException(400, 'password_required');
        Response::ok($this->app->auth->login($email, $password, $request));
    }

    private function twofa(Request $request): never
    {
        $token = $request->bearer_token ?? throw new HttpException(401, 'unauthenticated');
        $code = $request->str('code') ?? throw new HttpException(400, 'code_required');
        $this->app->auth->verifyTwofa($token, $code, $request);
        Response::ok(['status' => 'ok']);
    }

    private function logout(Request $request): never
    {
        if ($request->bearer_token !== null) {
            $this->app->auth->logout($request->bearer_token);
        }
        Response::ok();
    }

    private function me(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);
        $user = $this->app->db->one('SELECT email, lang, twofa_secret IS NOT NULL AS twofa_enabled FROM users WHERE id = ?', [$ctx->user_id]);
        Response::ok([
            'user_id' => $ctx->user_id,
            'email' => $ctx->email,
            'role' => $ctx->role,
            'lang' => $user['lang'] ?? 'hr',
            'twofa_enabled' => (bool) ($user['twofa_enabled'] ?? false),
            'twofa_enforced' => $this->app->auth->twofaMandatoryForRole($ctx->role),
            'recovery_codes_remaining' => $this->app->auth->recoveryCodesRemaining($ctx->user_id),
            'webauthn_keys' => (int) ($this->app->db->one(
                'SELECT COUNT(*) AS n FROM webauthn_credentials WHERE user_id = ?',
                [$ctx->user_id]
            )['n'] ?? 0),
            'subscription_ids' => $ctx->subscription_ids,
        ]);
    }

    /**
     * Auth za 2FA enrollment: puna sesija (dobrovoljno dodavanje 2FA) ILI ograničena
     * setup-sesija (forsirani enrollment obavezne 2FA, prije nego sesija prođe 2FA).
     */
    private function setupCtx(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        return $this->app->auth->authenticate($request)
            ?? $this->app->auth->setupSessionContext($request)
            ?? throw new HttpException(401, 'unauthenticated');
    }

    private function twofaSetup(Request $request): never
    {
        $ctx = $this->setupCtx($request);
        $secret = Totp::generateSecret();
        // Secret se sprema tek nakon potvrde ispravnim kodom (twofa/confirm)
        $this->app->db->run(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['twofa_pending_' . $ctx->user_id, json_encode(['secret' => $secret, 'ts' => time()])]
        );
        Response::ok([
            'secret' => $secret,
            'otpauth_uri' => Totp::otpauthUri($secret, $ctx->email),
        ]);
    }

    private function twofaConfirm(Request $request): never
    {
        $ctx = $this->setupCtx($request);
        $code = $request->str('code') ?? throw new HttpException(400, 'code_required');

        $pending = $this->app->db->one('SELECT value FROM settings WHERE `key` = ?', ['twofa_pending_' . $ctx->user_id]);
        $secret = $pending === null ? null : (json_decode((string) $pending['value'], true)['secret'] ?? null);
        if (!is_string($secret) || !Totp::verify($secret, $code)) {
            throw new HttpException(422, 'invalid_code');
        }

        $this->app->db->run('UPDATE users SET twofa_secret = ? WHERE id = ?', [$secret, $ctx->user_id]);
        $this->app->db->run('DELETE FROM settings WHERE `key` = ?', ['twofa_pending_' . $ctx->user_id]);
        // Recovery kodovi (anti-lockout) — prikazuju se KORISNIKU samo sada
        $recovery_codes = $this->app->auth->generateRecoveryCodes($ctx->user_id);
        // Ako je ovo bio forsirani enrollment (setup-sesija), podigni je na punu sesiju
        if ($request->bearer_token !== null) {
            $this->app->auth->markSessionTwofaPassed($request->bearer_token);
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'auth.twofa_enabled', null, $request->ip);
        Response::ok(['twofa_enabled' => true, 'recovery_codes' => $recovery_codes]);
    }

    /** Regeneracija recovery kodova (poništava stare). Samo uz već uključenu 2FA. */
    private function recoveryCodes(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);
        $user = $this->app->db->one('SELECT twofa_secret FROM users WHERE id = ?', [$ctx->user_id]);
        if ($user === null || $user['twofa_secret'] === null) {
            throw new HttpException(409, 'twofa_not_enabled');
        }
        $codes = $this->app->auth->generateRecoveryCodes($ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'auth.recovery_regenerated', null, $request->ip);
        Response::ok(['recovery_codes' => $codes]);
    }

    // ------------------------------------------------------------ WebAuthn drugi faktor

    /** Login challenge — pending session (lozinka prošla, 2FA još nije). */
    private function webauthnLoginOptions(Request $request): never
    {
        $token = $request->bearer_token ?? throw new HttpException(401, 'unauthenticated');
        Response::ok($this->app->auth->webauthnLoginOptions($token, $request->rpId()));
    }

    private function webauthnLogin(Request $request): never
    {
        $token = $request->bearer_token ?? throw new HttpException(401, 'unauthenticated');
        $this->app->auth->verifyWebauthnLogin($token, $request->body, $request->rpId(), $request->origin(), $request);
        Response::ok(['status' => 'ok']);
    }

    /** Challenge za registraciju novog ključa (puna autentikacija). */
    private function webauthnRegisterOptions(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);
        $challenge = WebAuthn::generateChallenge();
        $this->app->db->run(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['webauthn_reg_' . $ctx->user_id, json_encode(['challenge' => $challenge, 'ts' => time()])]
        );
        $existing = $this->app->db->all('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?', [$ctx->user_id]);
        Response::ok([
            'challenge' => $challenge,
            'rp_id' => $request->rpId(),
            'rp_name' => 'ForgePanel',
            'user_id' => WebAuthn::b64uEncode(pack('N', $ctx->user_id)),
            'user_name' => $ctx->email,
            'exclude' => array_column($existing, 'credential_id'),
        ]);
    }

    private function webauthnRegister(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);

        $label = trim($request->str('label') ?? '');
        if ($label === '' || mb_strlen($label) > 64) {
            throw new HttpException(422, 'invalid_label');
        }
        $challenge = $this->app->auth->consumeChallenge('webauthn_reg_' . $ctx->user_id)
            ?? throw new HttpException(422, 'challenge_expired');

        try {
            $credential = WebAuthn::verifyRegistration($request->body, $challenge, $request->rpId(), $request->origin());
        } catch (\RuntimeException $e) {
            throw new HttpException(422, 'invalid_credential: ' . $e->getMessage());
        }

        $transports = $request->body['transports'] ?? [];
        $this->app->db->run(
            'INSERT INTO webauthn_credentials (user_id, label, credential_id, public_key, alg, sign_count, transports)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $ctx->user_id,
                $label,
                $credential['credential_id'],
                $credential['public_key'],
                $credential['alg'],
                $credential['sign_count'],
                json_encode(is_array($transports) ? array_values(array_filter($transports, is_string(...))) : []),
            ]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'auth.webauthn_registered', ['label' => $label], $request->ip);
        Response::ok(['id' => $this->app->db->lastId(), 'label' => $label], 201);
    }

    private function webauthnKeys(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);
        Response::ok($this->app->db->all(
            'SELECT id, label, alg, transports, created_at, last_used_at FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at',
            [$ctx->user_id]
        ));
    }

    private function webauthnDelete(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);
        $deleted = $this->app->db->run(
            'DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?',
            [(int) $request->param('id'), $ctx->user_id]
        )->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404, 'not_found');
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'auth.webauthn_removed', ['key_id' => $request->param('id')], $request->ip);
        Response::ok();
    }
}
