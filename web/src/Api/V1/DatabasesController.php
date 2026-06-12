<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Crypto;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;
use ForgePanel\Web\Core\SignedToken;

final class DatabasesController extends Controller
{
    private const PMA_TOKEN_TTL_S = 60;

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/databases', $this->index(...));
        $router->add('POST', '/api/v1/databases', $this->create(...));
        $router->add('GET', '/api/v1/databases/pma/status', $this->pmaStatus(...));
        $router->add('POST', '/api/v1/databases/pma/install', $this->pmaInstall(...));
        $router->add('DELETE', '/api/v1/databases/{id}', $this->delete(...));
        $router->add('POST', '/api/v1/databases/{id}/users', $this->createUser(...));
        $router->add('PUT', '/api/v1/databases/{id}/users/{uid}', $this->updateUser(...));
        $router->add('DELETE', '/api/v1/databases/{id}/users/{uid}', $this->deleteUser(...));
        $router->add('POST', '/api/v1/databases/{id}/pma', $this->pmaLogin(...));
    }

    /** Pripoji listu DB usera (id, username, remote_access) svakoj bazi. @param list<array<string,mixed>> $dbs */
    private function withUsers(array $dbs): array
    {
        if ($dbs === []) {
            return $dbs;
        }
        $ids = array_map(static fn ($d) => (int) $d['id'], $dbs);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $users = $this->app->db->all(
            "SELECT id, database_id, username, remote_access FROM db_users WHERE database_id IN ($ph) ORDER BY username",
            $ids
        );
        $by_db = [];
        foreach ($users as $u) {
            $by_db[(int) $u['database_id']][] = $u;
        }
        foreach ($dbs as &$d) {
            $d['users'] = $by_db[(int) $d['id']] ?? [];
        }
        return $dbs;
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'databases:read');
        if ($ctx->isAdmin()) {
            Response::ok($this->withUsers($this->app->db->all('SELECT * FROM db_databases ORDER BY name')));
        }
        if ($ctx->subscription_ids === []) {
            Response::ok([]);
        }
        $placeholders = implode(',', array_fill(0, count($ctx->subscription_ids), '?'));
        Response::ok($this->withUsers($this->app->db->all(
            "SELECT * FROM db_databases WHERE subscription_id IN ($placeholders) ORDER BY name",
            $ctx->subscription_ids
        )));
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'databases:write');

        $name = strtolower(trim($request->str('name') ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name)) {
            throw new HttpException(422, 'invalid_database_name');
        }

        $subscription_id = $request->int('subscription_id')
            ?? ($ctx->subscription_ids[0] ?? ($ctx->isAdmin() ? $this->adminSubscription($ctx) : null));
        if ($subscription_id === null) {
            throw new HttpException(422, 'subscription_required');
        }
        $ctx->requireSubscription($subscription_id);

        $limit = $this->app->db->one(
            'SELECT p.max_databases, (SELECT COUNT(*) FROM db_databases d WHERE d.subscription_id = s.id) AS used
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?',
            [$subscription_id]
        ) ?? throw new HttpException(422, 'subscription_inactive');
        if (!$ctx->isAdmin() && (int) $limit['used'] >= (int) $limit['max_databases']) {
            throw new HttpException(422, 'plan_database_limit_reached');
        }

        if ($this->app->db->one('SELECT 1 FROM db_databases WHERE name = ?', [$name]) !== null) {
            throw new HttpException(409, 'database_exists');
        }

        $this->app->agent->call('db.create', ['name' => $name]);
        $this->app->db->run(
            'INSERT INTO db_databases (subscription_id, name) VALUES (?, ?)',
            [$subscription_id, $name]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'db.create', ['name' => $name], $request->ip);
        Response::ok(['id' => $this->app->db->lastId(), 'name' => $name], 201);
    }

    private function delete(Request $request): never
    {
        $ctx = $this->ctx($request, 'databases:write');
        $database = $this->databaseOr404($ctx, (int) $request->param('id'));

        $this->app->agent->call('db.delete', ['name' => $database['name']]);
        $this->app->db->run('DELETE FROM db_databases WHERE id = ?', [$database['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'db.delete', ['name' => $database['name']], $request->ip);
        Response::ok(['deleted' => $database['name']]);
    }

    private function createUser(Request $request): never
    {
        $ctx = $this->ctx($request, 'databases:write');
        $database = $this->databaseOr404($ctx, (int) $request->param('id'));

        $username = strtolower(trim($request->str('username') ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{2,31}$/', $username)) {
            throw new HttpException(422, 'invalid_username');
        }
        $password = $request->str('password') ?? '';
        if (strlen($password) < 12) {
            throw new HttpException(422, 'password_too_short');
        }

        $this->app->agent->call('db.user_create', [
            'username' => $username,
            'database' => $database['name'],
            'password' => $password,
            'remote_access' => $request->body['remote_access'] ?? false,
        ]);
        $this->app->db->run(
            'INSERT INTO db_users (subscription_id, username, database_id, remote_access, password_enc) VALUES (?, ?, ?, ?, ?)',
            [
                $database['subscription_id'],
                $username,
                $database['id'],
                (int) (bool) ($request->body['remote_access'] ?? false),
                (new Crypto($this->app->config))->encrypt($password), // phpMyAdmin auto-login
            ]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'db.user_create', ['username' => $username], $request->ip);
        Response::ok(['username' => $username], 201);
    }

    /** Promjena lozinke (opcionalno) i/ili remote pristupa za DB usera. */
    private function updateUser(Request $request): never
    {
        $ctx = $this->ctx($request, 'databases:write');
        $database = $this->databaseOr404($ctx, (int) $request->param('id'));
        $user = $this->dbUserOr404($database, (int) $request->param('uid'));

        $remote = (bool) ($request->body['remote_access'] ?? (int) $user['remote_access']);
        $crypto = new Crypto($this->app->config);

        $new_password = $request->str('password');
        if ($new_password !== null && $new_password !== '') {
            if (strlen($new_password) < 12) {
                throw new HttpException(422, 'password_too_short');
            }
            $password = $new_password;
        } elseif ($user['password_enc'] !== null) {
            // bez nove lozinke: zadrži postojeću (treba nam puna za recreate na drugom hostu)
            $password = $crypto->decrypt((string) $user['password_enc']);
        } else {
            throw new HttpException(422, 'password_required');
        }

        $this->app->agent->call('db.user_update', [
            'username' => $user['username'],
            'database' => $database['name'],
            'password' => $password,
            'remote_access' => $remote,
        ]);
        $this->app->db->run(
            'UPDATE db_users SET remote_access = ?, password_enc = ? WHERE id = ?',
            [(int) $remote, $crypto->encrypt($password), $user['id']]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'db.user_update', ['username' => $user['username']], $request->ip);
        Response::ok(['username' => $user['username']]);
    }

    private function deleteUser(Request $request): never
    {
        $ctx = $this->ctx($request, 'databases:write');
        $database = $this->databaseOr404($ctx, (int) $request->param('id'));
        $user = $this->dbUserOr404($database, (int) $request->param('uid'));

        $this->app->agent->call('db.user_delete', ['username' => $user['username']]);
        $this->app->db->run('DELETE FROM db_users WHERE id = ?', [$user['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'db.user_delete', ['username' => $user['username']], $request->ip);
        Response::ok(['deleted' => $user['username']]);
    }

    /** Je li phpMyAdmin instaliran na serveru (UI prikazuje "Instaliraj" ako nije). */
    private function pmaStatus(Request $request): never
    {
        $this->ctx($request, 'databases:read');
        Response::ok(['installed' => $this->pmaInstalled()]);
    }

    /**
     * Web sloj ima open_basedir (bez /opt/forgepanel/phpmyadmin), pa is_dir() tamo
     * uvijek faila — status čitamo iz DB zastavice koju agent postavi pri instalaciji.
     */
    private function pmaInstalled(): bool
    {
        return $this->app->db->one("SELECT 1 FROM components WHERE name = 'phpmyadmin' AND status = 'installed'") !== null;
    }

    /** Naknadna instalacija phpMyAdmina iz panela (admin). */
    private function pmaInstall(Request $request): never
    {
        $ctx = $this->ctx($request, 'databases:write');
        $ctx->requireRole('admin');
        $task_id = $this->app->tasks->enqueue('apps.phpmyadmin_install', [], $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'db.pma_install', null, $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    /** @param array<string,mixed> $database @return array<string,mixed> */
    private function dbUserOr404(array $database, int $uid): array
    {
        $user = $this->app->db->one('SELECT * FROM db_users WHERE id = ? AND database_id = ?', [$uid, $database['id']]);
        if ($user === null) {
            throw new HttpException(404, 'not_found');
        }
        return $user;
    }

    /** phpMyAdmin auto-login: signed one-time token → /pma-signon.php otvara PMA session. */
    private function pmaLogin(Request $request): never
    {
        $ctx = $this->ctx($request, 'databases:read');
        $database = $this->databaseOr404($ctx, (int) $request->param('id'));

        if (!$this->pmaInstalled()) {
            throw new HttpException(503, 'pma_not_installed');
        }
        $db_user = $this->app->db->one(
            'SELECT id FROM db_users WHERE database_id = ? AND password_enc IS NOT NULL ORDER BY id LIMIT 1',
            [$database['id']]
        ) ?? throw new HttpException(422, 'no_db_user_with_stored_password');

        $token = SignedToken::create(
            ['db_user_id' => (int) $db_user['id'], 'db' => $database['name']],
            $this->app->config->get('app_secret', ''),
            self::PMA_TOKEN_TTL_S
        );
        // Jednokratnost: jti se upisuje, signon ga troši
        $payload = SignedToken::verify($token, $this->app->config->get('app_secret', ''));
        $this->app->db->run(
            'INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['pma_jti_' . $payload['jti'], json_encode(['ts' => time()])]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'db.pma_login', ['database' => $database['name']], $request->ip);
        Response::ok(['url' => '/pma-signon.php?token=' . rawurlencode($token)]);
    }

    /** @return array<string, mixed> */
    private function databaseOr404(\ForgePanel\Web\Core\AuthContext $ctx, int $id): array
    {
        $database = $this->app->db->one('SELECT * FROM db_databases WHERE id = ?', [$id]);
        if ($database === null) {
            throw new HttpException(404, 'not_found');
        }
        $ctx->requireSubscription((int) $database['subscription_id']);
        return $database;
    }
}
