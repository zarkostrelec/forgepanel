<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Apps / WP toolkit — one-click WordPress + integritet core fileova. */
final class AppsController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('POST', '/api/v1/vhosts/{id}/apps/wordpress', $this->installWp(...));
        $router->add('POST', '/api/v1/vhosts/{id}/apps/wordpress/checksums', $this->checksums(...));
    }

    private function installWp(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        // Baza za WP — kreira se ako ne postoji
        $db_name = strtolower(trim($request->str('db_name') ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $db_name)) {
            throw new HttpException(422, 'invalid_db_name');
        }
        $db_user = strtolower(trim($request->str('db_user') ?? $db_name));
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $db_user)) {
            throw new HttpException(422, 'invalid_db_user');
        }
        $db_password = $request->str('db_password') ?? bin2hex(random_bytes(12));

        // Kreiraj bazu + usera kroz agent (ako ne postoje)
        if ($this->app->db->one('SELECT 1 FROM db_databases WHERE name = ?', [$db_name]) === null) {
            $this->app->agent->call('db.create', ['name' => $db_name]);
            $this->app->db->run('INSERT INTO db_databases (subscription_id, name) VALUES (?, ?)', [$vhost['subscription_id'], $db_name]);
            $this->app->agent->call('db.user_create', ['username' => $db_user, 'database' => $db_name, 'password' => $db_password]);
            $this->app->db->run('INSERT INTO db_users (subscription_id, username, database_id) VALUES (?, ?, ?)', [$vhost['subscription_id'], $db_user, $this->app->db->lastId()]);
        }

        $task_id = $this->app->tasks->enqueue('apps.wp_install', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'db_name' => $db_name,
            'db_user' => $db_user,
            'db_password' => $db_password,
        ], $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'apps.wp_install', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id, 'db_name' => $db_name, 'db_password' => $db_password], 202);
    }

    private function checksums(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $task_id = $this->app->tasks->enqueue('apps.wp_checksums', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
        ], $ctx->user_id);
        Response::ok(['task_id' => $task_id], 202);
    }
}
