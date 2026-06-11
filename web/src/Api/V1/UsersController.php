<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Auth;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Upravljanje korisnicima. Admin: svi. Reseller: kreira klijente i planove
 * UNUTAR svojih limita (poglavlje 4). Klijent ovdje nema pristup.
 */
final class UsersController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/users', $this->index(...));
        $router->add('POST', '/api/v1/users', $this->create(...));
        $router->add('PUT', '/api/v1/users/{id}/status', $this->setStatus(...));
        $router->add('DELETE', '/api/v1/users/{id}', $this->delete(...));
        $router->add('GET', '/api/v1/plans', $this->plans(...));
        $router->add('POST', '/api/v1/plans', $this->createPlan(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:read');
        $ctx->requireRole('admin', 'reseller');
        // Reseller vidi samo svoje klijente; admin sve
        $rows = $ctx->isAdmin()
            ? $this->app->db->all('SELECT u.id, u.email, u.status, u.last_login_at, r.name AS role, u.reseller_id FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.id')
            : $this->app->db->all('SELECT u.id, u.email, u.status, u.last_login_at, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.reseller_id = ? ORDER BY u.id', [$ctx->user_id]);
        Response::ok($rows);
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:write');
        $ctx->requireRole('admin', 'reseller');

        $email = strtolower(trim($request->str('email') ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'invalid_email');
        }
        $password = $request->str('password') ?? '';
        if (strlen($password) < 12) {
            throw new HttpException(422, 'password_too_short');
        }
        $role = $request->str('role') ?? 'client';
        // Reseller smije kreirati SAMO klijente; admin sve
        $allowed_roles = $ctx->isAdmin() ? ['admin', 'reseller', 'client'] : ['client'];
        if (!in_array($role, $allowed_roles, true)) {
            throw new HttpException(403, 'role_not_allowed');
        }
        if ($this->app->db->one('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
            throw new HttpException(409, 'email_exists');
        }

        $role_row = $this->app->db->one('SELECT id FROM roles WHERE name = ?', [$role]);
        $reseller_id = $ctx->isAdmin() ? ($role === 'client' ? $request->int('reseller_id') : null) : $ctx->user_id;

        $this->app->db->run(
            'INSERT INTO users (email, password_hash, role_id, reseller_id, lang) VALUES (?, ?, ?, ?, ?)',
            [$email, Auth::hashPassword($password), $role_row['id'], $reseller_id, $request->str('lang', 'hr')]
        );
        $user_id = $this->app->db->lastId();

        // Opcionalno: odmah subscription na plan
        $plan_id = $request->int('plan_id');
        if ($plan_id !== null && $role === 'client') {
            $this->assertPlanOwnership($ctx, $plan_id);
            $this->app->db->run('INSERT INTO subscriptions (user_id, plan_id) VALUES (?, ?)', [$user_id, $plan_id]);
        }

        $this->app->audit->log($ctx->user_id, $ctx->email, 'user.create', ['email' => $email, 'role' => $role], $request->ip);
        Response::ok(['id' => $user_id, 'email' => $email, 'role' => $role], 201);
    }

    private function setStatus(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:write');
        $ctx->requireRole('admin', 'reseller');
        $user = $this->targetUser($ctx, (int) $request->param('id'));

        $status = $request->str('status') ?? '';
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new HttpException(422, 'invalid_status');
        }
        $this->app->db->run('UPDATE users SET status = ? WHERE id = ?', [$status, $user['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'user.status', ['user_id' => $user['id'], 'status' => $status], $request->ip);
        Response::ok();
    }

    private function delete(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:write');
        $ctx->requireRole('admin', 'reseller');
        $user = $this->targetUser($ctx, (int) $request->param('id'));
        if ((int) $user['id'] === $ctx->user_id) {
            throw new HttpException(422, 'cannot_delete_self');
        }
        $this->app->db->run('DELETE FROM users WHERE id = ?', [$user['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'user.delete', ['user_id' => $user['id']], $request->ip);
        Response::ok();
    }

    private function plans(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:read');
        $ctx->requireRole('admin', 'reseller');
        // Globalni planovi (admin) + vlastiti planovi resellera
        $rows = $ctx->isAdmin()
            ? $this->app->db->all('SELECT * FROM plans ORDER BY id')
            : $this->app->db->all('SELECT * FROM plans WHERE owner_user_id = ? OR owner_user_id IS NULL ORDER BY id', [$ctx->user_id]);
        Response::ok($rows);
    }

    private function createPlan(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:write');
        $ctx->requireRole('admin', 'reseller');

        $name = trim($request->str('name') ?? '');
        if ($name === '' || mb_strlen($name) > 64) {
            throw new HttpException(422, 'invalid_name');
        }
        $php_versions = $request->body['php_versions'] ?? ['8.4'];
        if (!is_array($php_versions) || array_diff($php_versions, ['8.1', '8.2', '8.3', '8.4']) !== []) {
            throw new HttpException(422, 'invalid_php_versions');
        }

        // Reseller plan ne smije premašiti resellerove ukupne limite (pojednostavljeno: njegov plan)
        $owner = $ctx->isAdmin() ? null : $ctx->user_id;

        $this->app->db->run(
            'INSERT INTO plans (owner_user_id, name, disk_bytes, max_domains, max_mailboxes, max_databases, php_versions, features, cpu_quota_pct, memory_max_bytes, tasks_max)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $owner, $name,
                max(0, $request->int('disk_bytes', 10737418240)),
                max(1, $request->int('max_domains', 5)),
                max(0, $request->int('max_mailboxes', 10)),
                max(0, $request->int('max_databases', 5)),
                json_encode(array_values($php_versions)),
                json_encode($request->body['features'] ?? []),
                max(10, min(100, $request->int('cpu_quota_pct', 100))),
                max(134217728, $request->int('memory_max_bytes', 536870912)),
                max(32, $request->int('tasks_max', 128)),
            ]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'plan.create', ['name' => $name], $request->ip);
        Response::ok(['id' => $this->app->db->lastId(), 'name' => $name], 201);
    }

    /** Reseller smije dirati samo svoje klijente. @return array<string, mixed> */
    private function targetUser(\ForgePanel\Web\Core\AuthContext $ctx, int $id): array
    {
        $user = $this->app->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null) {
            throw new HttpException(404, 'not_found');
        }
        if (!$ctx->isAdmin() && (int) ($user['reseller_id'] ?? 0) !== $ctx->user_id) {
            throw new HttpException(404, 'not_found');
        }
        return $user;
    }

    private function assertPlanOwnership(\ForgePanel\Web\Core\AuthContext $ctx, int $plan_id): void
    {
        $plan = $this->app->db->one('SELECT owner_user_id FROM plans WHERE id = ?', [$plan_id]);
        if ($plan === null) {
            throw new HttpException(422, 'invalid_plan');
        }
        if (!$ctx->isAdmin() && $plan['owner_user_id'] !== null && (int) $plan['owner_user_id'] !== $ctx->user_id) {
            throw new HttpException(403, 'plan_not_yours');
        }
    }
}
