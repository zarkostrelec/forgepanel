<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Per-user API tokeni sa scopovima — korisnik automatizira SVOJE resurse, ničije druge. */
final class TokensController extends Controller
{
    private const VALID_SCOPES = [
        'vhosts:read', 'vhosts:write', 'databases:read', 'databases:write',
        'files:read', 'files:write', 'tasks:read', 'monitoring:read',
        'ssl:read', 'ssl:write', 'cron:read', 'cron:write',
        'dns:read', 'dns:write', 'ftp:read', 'ftp:write', 'backup:read', 'backup:write',
        'mail:read', 'mail:write', 'updates:read', 'updates:write',
        'docker:read', 'docker:write', 'security:read', 'security:write', 'firewall:write',
    ];

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/tokens', $this->index(...));
        $router->add('POST', '/api/v1/tokens', $this->create(...));
        $router->add('DELETE', '/api/v1/tokens/{id}', $this->revoke(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);
        Response::ok($this->app->db->all(
            'SELECT id, name, scopes, expires_at, last_used_at, created_at FROM api_tokens WHERE user_id = ?',
            [$ctx->user_id]
        ));
    }

    private function create(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);

        $name = trim($request->str('name') ?? '');
        if ($name === '' || mb_strlen($name) > 64) {
            throw new HttpException(422, 'invalid_name');
        }
        $scopes = $request->body['scopes'] ?? [];
        if (!is_array($scopes) || $scopes === [] || array_diff($scopes, self::VALID_SCOPES) !== []) {
            throw new HttpException(422, 'invalid_scopes');
        }

        $token = 'fp_' . bin2hex(random_bytes(32));
        $this->app->db->run(
            'INSERT INTO api_tokens (user_id, name, token_hash, scopes, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$ctx->user_id, $name, hash('sha256', $token), json_encode(array_values($scopes)), $request->str('expires_at')]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'token.create', ['name' => $name], $request->ip);
        // Token se prikazuje JEDNOM — sprema se samo hash
        Response::ok(['token' => $token, 'id' => $this->app->db->lastId()], 201);
    }

    private function revoke(Request $request): never
    {
        $ctx = $this->app->auth->requireAuth($request);
        $deleted = $this->app->db->run(
            'DELETE FROM api_tokens WHERE id = ? AND user_id = ?',
            [(int) $request->param('id'), $ctx->user_id]
        )->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404, 'not_found');
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'token.revoke', ['token_id' => $request->param('id')], $request->ip);
        Response::ok();
    }
}
