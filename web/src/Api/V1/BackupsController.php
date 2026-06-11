<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class BackupsController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/backups', $this->index(...));
        $router->add('POST', '/api/v1/vhosts/{id}/backups', $this->create(...));
        $router->add('POST', '/api/v1/backups/{id}/restore', $this->restore(...));
        $router->add('DELETE', '/api/v1/backups/{id}', $this->delete(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'backup:read');
        if ($ctx->isAdmin()) {
            Response::ok($this->app->db->all('SELECT * FROM backups ORDER BY id DESC LIMIT 200'));
        }
        if ($ctx->subscription_ids === []) {
            Response::ok([]);
        }
        $placeholders = implode(',', array_fill(0, count($ctx->subscription_ids), '?'));
        Response::ok($this->app->db->all(
            "SELECT * FROM backups WHERE subscription_id IN ($placeholders) ORDER BY id DESC LIMIT 200",
            $ctx->subscription_ids
        ));
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'backup:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $include_db = (bool) ($request->body['include_databases'] ?? true);
        $keep = max(1, min(365, $request->int('keep', 7) ?? 7));

        $databases = $include_db
            ? array_column($this->app->db->all(
                'SELECT name FROM db_databases WHERE subscription_id = ?',
                [$vhost['subscription_id']]
            ), 'name')
            : [];

        $destination_id = $this->localDestinationId();
        $this->app->db->run(
            "INSERT INTO backups (subscription_id, destination_id, type, path, status)
             VALUES (?, ?, 'full', '', 'running')",
            [$vhost['subscription_id'], $destination_id]
        );
        $backup_id = $this->app->db->lastId();

        $task_id = $this->app->tasks->enqueue('backup.vhost_create', [
            'backup_id' => $backup_id,
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'databases' => $databases,
            'keep' => $keep,
        ], $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'backup.create', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['backup_id' => $backup_id, 'task_id' => $task_id], 202);
    }

    private function restore(Request $request): never
    {
        $ctx = $this->ctx($request, 'backup:write');
        $backup = $this->backupOr404($ctx, (int) $request->param('id'));

        $mode = $request->str('mode') ?? 'files';
        if (!in_array($mode, ['files', 'db'], true)) {
            throw new HttpException(422, 'invalid_mode');
        }

        $manifest = json_decode((string) ($backup['manifest'] ?? 'null'), true);
        $domain = is_array($manifest) ? ($manifest['domain'] ?? null) : null;
        if (!is_string($domain)) {
            throw new HttpException(422, 'backup_without_manifest');
        }
        $vhost = $this->app->db->one('SELECT * FROM vhosts WHERE domain = ?', [$domain])
            ?? throw new HttpException(422, 'vhost_gone');
        $ctx->requireSubscription((int) $vhost['subscription_id']);

        $params = [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $domain,
            'path' => $backup['path'],
            'mode' => $mode,
        ];
        if ($mode === 'db') {
            $db_name = $request->str('db_name') ?? '';
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $db_name)) {
                throw new HttpException(422, 'invalid_db_name');
            }
            $params['db_name'] = $db_name;
        }

        $task_id = $this->app->tasks->enqueue('backup.restore', $params, $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'backup.restore', ['domain' => $domain, 'mode' => $mode], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    private function delete(Request $request): never
    {
        $ctx = $this->ctx($request, 'backup:write');
        $backup = $this->backupOr404($ctx, (int) $request->param('id'));

        if ($backup['path'] !== '') {
            $this->app->agent->call('backup.delete', ['path' => $backup['path']], timeout_s: 120);
        }
        $this->app->db->run('DELETE FROM backups WHERE id = ?', [$backup['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'backup.delete', ['path' => $backup['path']], $request->ip);
        Response::ok();
    }

    private function localDestinationId(): int
    {
        $row = $this->app->db->one("SELECT id FROM backup_destinations WHERE type = 'local' LIMIT 1");
        if ($row !== null) {
            return (int) $row['id'];
        }
        $admin = $this->app->db->one(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin' ORDER BY u.id LIMIT 1"
        ) ?? throw new HttpException(500, 'no_admin');
        $this->app->db->run(
            "INSERT INTO backup_destinations (owner_user_id, name, type, config)
             VALUES (?, 'Lokalni disk', 'local', '{\"path\": \"/var/backups/forgepanel\"}')",
            [$admin['id']]
        );
        return $this->app->db->lastId();
    }

    /** @return array<string, mixed> */
    private function backupOr404(\ForgePanel\Web\Core\AuthContext $ctx, int $id): array
    {
        $backup = $this->app->db->one('SELECT * FROM backups WHERE id = ?', [$id]);
        if ($backup === null) {
            throw new HttpException(404, 'not_found');
        }
        if ($backup['subscription_id'] !== null) {
            $ctx->requireSubscription((int) $backup['subscription_id']);
        } else {
            $ctx->requireRole('admin');
        }
        return $backup;
    }
}
