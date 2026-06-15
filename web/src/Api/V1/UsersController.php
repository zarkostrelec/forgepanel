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
        $router->add('PUT', '/api/v1/users/{id}', $this->update(...));
        $router->add('DELETE', '/api/v1/users/{id}', $this->delete(...));
        $router->add('GET', '/api/v1/subscriptions', $this->subscriptions(...));
        $router->add('GET', '/api/v1/plans', $this->plans(...));
        $router->add('POST', '/api/v1/plans', $this->createPlan(...));
        $router->add('PUT', '/api/v1/plans/{id}', $this->updatePlan(...));
        $router->add('DELETE', '/api/v1/plans/{id}', $this->deletePlan(...));
        $router->add('GET', '/api/v1/reseller/quota', $this->resellerQuota(...));
    }

    /**
     * Reseller kvota: ukupni limit (njegov dodijeljeni paket) vs. već raspodijeljeno
     * klijentima. Reseller vidi svoj; admin može tražiti ?reseller_id.
     */
    private function resellerQuota(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:read');
        $ctx->requireRole('admin', 'reseller');
        $rid = $ctx->isAdmin() && $request->query('reseller_id') !== null
            ? (int) $request->query('reseller_id') : $ctx->user_id;
        Response::ok([
            'reseller_id' => $rid,
            'ceiling' => $this->resellerCeiling($rid),
            'allocated' => $this->resellerAllocated($rid),
        ]);
    }

    /** Ukupni limit resellera (zbroj njegovih aktivnih pretplata-paketa) ili null ako nema paket. */
    private function resellerCeiling(int $reseller_id): ?array
    {
        $row = $this->app->db->one(
            "SELECT COALESCE(SUM(p.max_domains),0) AS max_domains, COALESCE(SUM(p.max_mailboxes),0) AS max_mailboxes,
                    COALESCE(SUM(p.max_databases),0) AS max_databases, COALESCE(SUM(p.disk_bytes),0) AS disk_bytes,
                    COUNT(*) AS n
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? AND s.status = 'active'",
            [$reseller_id]
        );
        return ($row === null || (int) $row['n'] === 0) ? null : [
            'max_domains' => (int) $row['max_domains'], 'max_mailboxes' => (int) $row['max_mailboxes'],
            'max_databases' => (int) $row['max_databases'], 'disk_bytes' => (int) $row['disk_bytes'],
        ];
    }

    /** Već raspodijeljeno klijentima resellera (zbroj limita planova njihovih aktivnih pretplata). */
    private function resellerAllocated(int $reseller_id): array
    {
        $row = $this->app->db->one(
            "SELECT COALESCE(SUM(p.max_domains),0) AS max_domains, COALESCE(SUM(p.max_mailboxes),0) AS max_mailboxes,
                    COALESCE(SUM(p.max_databases),0) AS max_databases, COALESCE(SUM(p.disk_bytes),0) AS disk_bytes
             FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN plans p ON p.id = s.plan_id
             WHERE u.reseller_id = ? AND s.status = 'active'",
            [$reseller_id]
        );
        return [
            'max_domains' => (int) ($row['max_domains'] ?? 0), 'max_mailboxes' => (int) ($row['max_mailboxes'] ?? 0),
            'max_databases' => (int) ($row['max_databases'] ?? 0), 'disk_bytes' => (int) ($row['disk_bytes'] ?? 0),
        ];
    }

    /** Reseller ne smije prodati više nego što mu paket dopušta. Bez paketa = bez gatea. */
    private function assertResellerCapacity(int $reseller_id, int $new_plan_id): void
    {
        $ceiling = $this->resellerCeiling($reseller_id);
        if ($ceiling === null) {
            return;
        }
        $alloc = $this->resellerAllocated($reseller_id);
        $new = $this->app->db->one(
            'SELECT max_domains, max_mailboxes, max_databases, disk_bytes FROM plans WHERE id = ?',
            [$new_plan_id]
        ) ?? throw new HttpException(422, 'invalid_plan');
        foreach (['max_domains', 'max_mailboxes', 'max_databases', 'disk_bytes'] as $k) {
            if ((int) $alloc[$k] + (int) $new[$k] > (int) $ceiling[$k]) {
                throw new HttpException(422, 'reseller_quota_exceeded');
            }
        }
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

        // Opcionalno: odmah subscription na plan (klijent → plan; reseller → paket/kvota)
        $plan_id = $request->int('plan_id');
        if ($plan_id !== null && in_array($role, ['client', 'reseller'], true)) {
            $this->assertPlanOwnership($ctx, $plan_id);
            // Reseller koji kreira klijenta: ne smije premašiti svoj paket
            if (!$ctx->isAdmin() && $role === 'client') {
                $this->assertResellerCapacity($ctx->user_id, $plan_id);
            }
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
        // Ne dopusti zaključavanje panela: zadnji aktivni admin ne smije u suspended
        if ($status === 'suspended' && $this->isLastActiveAdmin((int) $user['id'])) {
            throw new HttpException(422, 'last_admin');
        }
        $this->app->db->run('UPDATE users SET status = ? WHERE id = ?', [$status, $user['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'user.status', ['user_id' => $user['id'], 'status' => $status], $request->ip);
        Response::ok();
    }

    private function update(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:write');
        $ctx->requireRole('admin', 'reseller');
        $user = $this->targetUser($ctx, (int) $request->param('id'));

        $fields = [];
        $params = [];

        // E-mail (opcionalno) — provjera formata i jedinstvenosti
        $email_raw = $request->str('email');
        if ($email_raw !== null) {
            $email = strtolower(trim($email_raw));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new HttpException(422, 'invalid_email');
            }
            if ($email !== $user['email']
                && $this->app->db->one('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $user['id']]) !== null) {
                throw new HttpException(409, 'email_exists');
            }
            $fields[] = 'email = ?';
            $params[] = $email;
        }

        // Lozinka (opcionalno) — samo ako je poslana neprazna
        $password = $request->str('password');
        if ($password !== null && $password !== '') {
            if (strlen($password) < 12) {
                throw new HttpException(422, 'password_too_short');
            }
            $fields[] = 'password_hash = ?';
            $params[] = Auth::hashPassword($password);
        }

        // Jezik (opcionalno)
        $lang = $request->str('lang');
        if ($lang !== null) {
            if (!in_array($lang, ['hr', 'en'], true)) {
                throw new HttpException(422, 'invalid_lang');
            }
            $fields[] = 'lang = ?';
            $params[] = $lang;
        }

        // Rola (opcionalno, samo admin smije mijenjati)
        $role = $request->str('role');
        if ($role !== null) {
            if (!$ctx->isAdmin()) {
                throw new HttpException(403, 'role_not_allowed');
            }
            if (!in_array($role, ['admin', 'reseller', 'client'], true)) {
                throw new HttpException(422, 'invalid_role');
            }
            if ((int) $user['id'] === $ctx->user_id && $role !== 'admin') {
                throw new HttpException(422, 'cannot_demote_self');
            }
            $role_row = $this->app->db->one('SELECT id FROM roles WHERE name = ?', [$role]);
            $fields[] = 'role_id = ?';
            $params[] = $role_row['id'];
        }

        if ($fields === []) {
            throw new HttpException(422, 'nothing_to_update');
        }

        $params[] = $user['id'];
        $this->app->db->run('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'user.update', ['user_id' => (int) $user['id']], $request->ip);
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
        if ($this->isLastActiveAdmin((int) $user['id'])) {
            throw new HttpException(422, 'last_admin');
        }
        $this->app->db->run('DELETE FROM users WHERE id = ?', [$user['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'user.delete', ['user_id' => $user['id']], $request->ip);
        Response::ok();
    }

    /**
     * Aktivne pretplate za izbor pri kreiranju domene (admin: sve; reseller: svojih
     * klijenata + svoje). Vraća id + email klijenta + naziv plana.
     */
    private function subscriptions(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:read');
        $ctx->requireRole('admin', 'reseller');
        $rows = $ctx->isAdmin()
            ? $this->app->db->all(
                "SELECT s.id, u.email, p.name AS plan
                 FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN plans p ON p.id = s.plan_id
                 WHERE s.status = 'active' ORDER BY u.email, p.name")
            : $this->app->db->all(
                "SELECT s.id, u.email, p.name AS plan
                 FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN plans p ON p.id = s.plan_id
                 WHERE s.status = 'active' AND (u.reseller_id = ? OR u.id = ?) ORDER BY u.email, p.name",
                [$ctx->user_id, $ctx->user_id]);
        Response::ok($rows);
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
        $php_versions = $request->body['php_versions'] ?? ['8.5'];
        if (!is_array($php_versions) || $php_versions === []
            || array_diff($php_versions, ['8.1', '8.2', '8.3', '8.4', '8.5']) !== []) {
            throw new HttpException(422, 'invalid_php_versions');
        }

        $owner = $ctx->isAdmin() ? null : $ctx->user_id;
        // "Reseller paket" (grupa) = plan koji se dodjeljuje reselleru kao njegova
        // ukupna kvota; samo admin ga smije označiti.
        $features = is_array($request->body['features'] ?? null) ? $request->body['features'] : [];
        $features['reseller'] = $ctx->isAdmin() && (bool) ($request->body['reseller'] ?? false);

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
                json_encode($features),
                max(10, min(100, $request->int('cpu_quota_pct', 100))),
                max(134217728, $request->int('memory_max_bytes', 536870912)),
                max(32, $request->int('tasks_max', 128)),
            ]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'plan.create', ['name' => $name], $request->ip);
        Response::ok(['id' => $this->app->db->lastId(), 'name' => $name], 201);
    }

    private function updatePlan(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:write');
        $ctx->requireRole('admin', 'reseller');
        $plan = $this->targetPlan($ctx, (int) $request->param('id'));

        $name = trim($request->str('name') ?? '');
        if ($name === '' || mb_strlen($name) > 64) {
            throw new HttpException(422, 'invalid_name');
        }
        $php_versions = $request->body['php_versions'] ?? json_decode($plan['php_versions'] ?: '[]', true);
        if (!is_array($php_versions) || $php_versions === []
            || array_diff($php_versions, ['8.1', '8.2', '8.3', '8.4', '8.5']) !== []) {
            throw new HttpException(422, 'invalid_php_versions');
        }

        $features = is_array($request->body['features'] ?? null)
            ? $request->body['features']
            : (json_decode($plan['features'] ?: '{}', true) ?: []);
        if (array_key_exists('reseller', $request->body)) {
            $features['reseller'] = $ctx->isAdmin() && (bool) $request->body['reseller'];
        }

        $this->app->db->run(
            'UPDATE plans SET name = ?, disk_bytes = ?, max_domains = ?, max_mailboxes = ?, max_databases = ?, php_versions = ?, features = ?, cpu_quota_pct = ?, memory_max_bytes = ?, tasks_max = ? WHERE id = ?',
            [
                $name,
                max(0, $request->int('disk_bytes', (int) $plan['disk_bytes'])),
                max(1, $request->int('max_domains', (int) $plan['max_domains'])),
                max(0, $request->int('max_mailboxes', (int) $plan['max_mailboxes'])),
                max(0, $request->int('max_databases', (int) $plan['max_databases'])),
                json_encode(array_values($php_versions)),
                json_encode($features),
                max(10, min(100, $request->int('cpu_quota_pct', (int) $plan['cpu_quota_pct']))),
                max(134217728, $request->int('memory_max_bytes', (int) $plan['memory_max_bytes'])),
                max(32, $request->int('tasks_max', (int) $plan['tasks_max'])),
                $plan['id'],
            ]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'plan.update', ['plan_id' => (int) $plan['id'], 'name' => $name], $request->ip);
        Response::ok(['id' => (int) $plan['id'], 'name' => $name]);
    }

    private function deletePlan(Request $request): never
    {
        $ctx = $this->ctx($request, 'users:write');
        $ctx->requireRole('admin', 'reseller');
        $plan = $this->targetPlan($ctx, (int) $request->param('id'));

        // Plan u upotrebi (postoji pretplata) ne smije se brisati
        if ($this->app->db->one('SELECT 1 FROM subscriptions WHERE plan_id = ?', [$plan['id']]) !== null) {
            throw new HttpException(409, 'plan_in_use');
        }
        $this->app->db->run('DELETE FROM plans WHERE id = ?', [$plan['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'plan.delete', ['plan_id' => (int) $plan['id']], $request->ip);
        Response::ok();
    }

    /** True ako je dani korisnik aktivan admin i jedini takav (suspend/delete bi zaključao panel). */
    private function isLastActiveAdmin(int $user_id): bool
    {
        $target = $this->app->db->one(
            "SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND r.name = 'admin' AND u.status = 'active'",
            [$user_id]
        );
        if ($target === null) {
            return false;
        }
        $count = $this->app->db->one(
            "SELECT COUNT(*) AS n FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.name = 'admin' AND u.status = 'active'"
        );
        return (int) ($count['n'] ?? 0) <= 1;
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

    /** Reseller smije uređivati/brisati samo VLASTITE planove (ne globalne admin planove). @return array<string, mixed> */
    private function targetPlan(\ForgePanel\Web\Core\AuthContext $ctx, int $id): array
    {
        $plan = $this->app->db->one('SELECT * FROM plans WHERE id = ?', [$id]);
        if ($plan === null) {
            throw new HttpException(404, 'not_found');
        }
        if (!$ctx->isAdmin() && (int) ($plan['owner_user_id'] ?? 0) !== $ctx->user_id) {
            throw new HttpException(404, 'not_found');
        }
        return $plan;
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
