<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Auth;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;
use ForgePanel\Web\Core\Totp;

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
            'subscription_ids' => $ctx->subscription_ids,
        ]);
    }

    private function twofaSetup(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);
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
        $ctx = $this->app->auth->requireAuth($request);
        $code = $request->str('code') ?? throw new HttpException(400, 'code_required');

        $pending = $this->app->db->one('SELECT value FROM settings WHERE `key` = ?', ['twofa_pending_' . $ctx->user_id]);
        $secret = $pending === null ? null : (json_decode((string) $pending['value'], true)['secret'] ?? null);
        if (!is_string($secret) || !Totp::verify($secret, $code)) {
            throw new HttpException(422, 'invalid_code');
        }

        $this->app->db->run('UPDATE users SET twofa_secret = ? WHERE id = ?', [$secret, $ctx->user_id]);
        $this->app->db->run('DELETE FROM settings WHERE `key` = ?', ['twofa_pending_' . $ctx->user_id]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'auth.twofa_enabled', null, $request->ip);
        Response::ok(['twofa_enabled' => true]);
    }
}
