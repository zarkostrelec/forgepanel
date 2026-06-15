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
    /** Statički katalog marketplace aplikacija (1-click). */
    private const CATALOG = [
        ['id' => 'wordpress', 'name' => 'WordPress', 'kind' => 'php', 'needs_db' => true,
            'desc' => 'Najpopularniji CMS — core + wp-config sa sigurnosnim saltovima.'],
        ['id' => 'nextcloud', 'name' => 'Nextcloud', 'kind' => 'php', 'needs_db' => true,
            'desc' => 'Samostalni cloud (fileovi, kalendar, kontakti) — occ instalacija, spreman za prijavu.'],
        ['id' => 'ghost', 'name' => 'Ghost', 'kind' => 'node', 'needs_db' => true,
            'desc' => 'Moderni publishing/blog (Node) — ghost-cli + systemd + nginx proxy.'],
        ['id' => 'node', 'name' => 'Node.js runtime', 'kind' => 'runtime', 'needs_db' => false,
            'desc' => 'Pokreni vlastitu Node aplikaciju kao systemd servis iza nginx proxyja.'],
        ['id' => 'python', 'name' => 'Python runtime', 'kind' => 'runtime', 'needs_db' => false,
            'desc' => 'Pokreni WSGI/ASGI aplikaciju (gunicorn/uvicorn) u venvu iza nginx proxyja.'],
    ];

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/apps/catalog', $this->catalog(...));
        $router->add('POST', '/api/v1/vhosts/{id}/apps/wordpress', $this->installWp(...));
        $router->add('POST', '/api/v1/vhosts/{id}/apps/wordpress/checksums', $this->checksums(...));
        $router->add('POST', '/api/v1/vhosts/{id}/apps/nextcloud', $this->installNextcloud(...));
        $router->add('POST', '/api/v1/vhosts/{id}/apps/ghost', $this->installGhost(...));
    }

    private function catalog(Request $request): never
    {
        $this->ctx($request, 'vhosts:read');
        Response::ok(self::CATALOG);
    }

    private function installWp(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        [$db_name, $db_user, $db_password] = $this->ensureDb($request, $vhost);

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

    private function installNextcloud(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        [$db_name, $db_user, $db_password] = $this->ensureDb($request, $vhost, 'nc_');
        $admin_password = bin2hex(random_bytes(8));

        $task_id = $this->app->tasks->enqueue('apps.install', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'type' => 'nextcloud',
            'db_name' => $db_name,
            'db_user' => $db_user,
            'db_password' => $db_password,
            'admin_password' => $admin_password,
        ], $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'apps.install', ['type' => 'nextcloud', 'domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id, 'admin_user' => 'admin', 'admin_password' => $admin_password], 202);
    }

    private function installGhost(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        [$db_name, $db_user, $db_password] = $this->ensureDb($request, $vhost, 'ghost_');

        $task_id = $this->app->tasks->enqueue('apps.install', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'type' => 'ghost',
            'db_name' => $db_name,
            'db_user' => $db_user,
            'db_password' => $db_password,
        ], $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'apps.install', ['type' => 'ghost', 'domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    /**
     * Kreira bazu + usera za aplikaciju (idempotentno) i vraća [db_name, db_user, db_password].
     * @param array<string, mixed> $vhost
     * @return array{0: string, 1: string, 2: string}
     */
    private function ensureDb(Request $request, array $vhost, string $prefix = 'wp_'): array
    {
        $default = $prefix . preg_replace('/[^a-z0-9]/', '_', strtolower((string) $vhost['domain']));
        $db_name = strtolower(trim($request->str('db_name') ?? substr($default, 0, 60)));
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $db_name)) {
            throw new HttpException(422, 'invalid_db_name');
        }
        $db_user = strtolower(trim($request->str('db_user') ?? substr($db_name, 0, 32)));
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $db_user)) {
            throw new HttpException(422, 'invalid_db_user');
        }
        $db_password = $request->str('db_password') ?? bin2hex(random_bytes(12));

        if ($this->app->db->one('SELECT 1 FROM db_databases WHERE name = ?', [$db_name]) === null) {
            $this->app->agent->call('db.create', ['name' => $db_name]);
            $this->app->db->run('INSERT INTO db_databases (subscription_id, name) VALUES (?, ?)', [$vhost['subscription_id'], $db_name]);
            $this->app->agent->call('db.user_create', ['username' => $db_user, 'database' => $db_name, 'password' => $db_password]);
            $this->app->db->run('INSERT INTO db_users (subscription_id, username, database_id) VALUES (?, ?, ?)', [$vhost['subscription_id'], $db_user, $this->app->db->lastId()]);
        }
        return [$db_name, $db_user, $db_password];
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
