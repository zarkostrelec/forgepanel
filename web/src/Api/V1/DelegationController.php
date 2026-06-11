<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Auth;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Delegirani pristup: klijent daje svom developeru pristup SAMO određenoj
 * domeni, granularno (files+git, ali ne mail/backup). Plesk ovo ima polovično.
 */
final class DelegationController extends Controller
{
    public const PERMS = ['files', 'git', 'cron', 'databases', 'ftp', 'mail', 'backup'];

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/vhosts/{id}/delegates', $this->index(...));
        $router->add('POST', '/api/v1/vhosts/{id}/delegates', $this->grant(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}/delegates/{did}', $this->revoke(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        Response::ok($this->app->db->all(
            'SELECT d.id, u.email AS grantee, d.permissions
             FROM delegated_access d JOIN users u ON u.id = d.grantee_user_id
             WHERE d.vhost_id = ? AND d.grantor_user_id = ?',
            [$vhost['id'], $ctx->user_id]
        ));
    }

    private function grant(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $email = strtolower(trim($request->str('email') ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'invalid_email');
        }
        $perms = $request->body['permissions'] ?? [];
        if (!is_array($perms) || $perms === [] || array_diff($perms, self::PERMS) !== []) {
            throw new HttpException(422, 'invalid_permissions');
        }

        // Developer dobiva client račun ako ne postoji (bez vlastitih resursa)
        $grantee = $this->app->db->one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($grantee === null) {
            $password = $request->str('password') ?? '';
            if (strlen($password) < 12) {
                throw new HttpException(422, 'new_user_needs_password');
            }
            $client_role = $this->app->db->one("SELECT id FROM roles WHERE name = 'client'");
            $this->app->db->run(
                'INSERT INTO users (email, password_hash, role_id, reseller_id) VALUES (?, ?, ?, ?)',
                [$email, Auth::hashPassword($password), $client_role['id'], null]
            );
            $grantee_id = $this->app->db->lastId();
        } else {
            $grantee_id = (int) $grantee['id'];
        }
        if ($grantee_id === $ctx->user_id) {
            throw new HttpException(422, 'cannot_delegate_to_self');
        }

        $this->app->db->run(
            'INSERT INTO delegated_access (grantor_user_id, grantee_user_id, vhost_id, permissions) VALUES (?, ?, ?, ?)',
            [$ctx->user_id, $grantee_id, $vhost['id'], json_encode(array_values($perms))]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'delegation.grant', ['email' => $email, 'domain' => $vhost['domain'], 'perms' => $perms], $request->ip);
        Response::ok(['id' => $this->app->db->lastId()], 201);
    }

    private function revoke(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $deleted = $this->app->db->run(
            'DELETE FROM delegated_access WHERE id = ? AND vhost_id = ? AND grantor_user_id = ?',
            [(int) $request->param('did'), $vhost['id'], $ctx->user_id]
        )->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404, 'not_found');
        }
        Response::ok();
    }
}
