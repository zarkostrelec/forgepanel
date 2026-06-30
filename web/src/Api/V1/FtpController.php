<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** FTP virtualni useri — vezani na vhost path, TLS obavezan (ProFTPD). */
final class FtpController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/vhosts/{id}/ftp', $this->index(...));
        $router->add('POST', '/api/v1/vhosts/{id}/ftp', $this->create(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}/ftp/{user_id}', $this->delete(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'ftp:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'), 'ftp');
        Response::ok($this->app->db->all(
            'SELECT id, username, home_path, status FROM ftp_users WHERE vhost_id = ?',
            [$vhost['id']]
        ));
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'ftp:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'), 'ftp');

        $username = strtolower(trim($request->str('username') ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_.-]{2,31}$/', $username)) {
            throw new HttpException(422, 'invalid_username');
        }
        $password = $request->str('password') ?? '';
        if (strlen($password) < 12) {
            throw new HttpException(422, 'password_too_short');
        }
        if ($this->app->db->one('SELECT 1 FROM ftp_users WHERE username = ?', [$username]) !== null) {
            throw new HttpException(409, 'username_exists');
        }

        // Home unutar vhost roota (leksička normalizacija; agent radi realpath provjeru)
        $relative = trim($request->str('home') ?? '/httpdocs');
        $parts = [];
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new HttpException(422, 'path_escape');
            }
            $parts[] = $part;
        }
        $home = rtrim('/var/www/vhosts/' . $vhost['domain'] . '/' . implode('/', $parts), '/');

        $hash = crypt($password, '$6$' . bin2hex(random_bytes(8)) . '$');

        $this->app->db->run(
            'INSERT INTO ftp_users (vhost_id, username, password_hash, home_path) VALUES (?, ?, ?, ?)',
            [$vhost['id'], $username, $hash, $home]
        );
        $user_id = $this->app->db->lastId();
        try {
            $this->sync();
        } catch (\Throwable $e) {
            $this->app->db->run('DELETE FROM ftp_users WHERE id = ?', [$user_id]);
            throw $e;
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'ftp.user_create', ['username' => $username, 'domain' => $vhost['domain']], $request->ip);
        Response::ok(['id' => $user_id, 'username' => $username], 201);
    }

    private function delete(Request $request): never
    {
        $ctx = $this->ctx($request, 'ftp:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'), 'ftp');

        $deleted = $this->app->db->run(
            'DELETE FROM ftp_users WHERE id = ? AND vhost_id = ?',
            [(int) $request->param('user_id'), $vhost['id']]
        )->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404, 'not_found');
        }
        $this->sync();
        $this->app->audit->log($ctx->user_id, $ctx->email, 'ftp.user_delete', ['domain' => $vhost['domain']], $request->ip);
        Response::ok();
    }

    /** Passwd file je globalan — sinkronizira se IZ baze za sve vhostove (aktivni useri). */
    private function sync(): void
    {
        $users = $this->app->db->all(
            "SELECT f.username, f.password_hash, f.home_path AS home, v.sys_user
             FROM ftp_users f JOIN vhosts v ON v.id = f.vhost_id
             WHERE f.status = 'active'"
        );
        $this->app->agent->call('ftp.sync', ['users' => $users], timeout_s: 180);
    }
}
