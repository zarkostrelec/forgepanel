<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Bulk operacije — masovna promjena PHP verzije, SSL renew, suspend,
 * malware scan preko selekcije vhostova. Plesk/cPanel sve tjeraju klik-po-klik.
 */
final class BulkController extends Controller
{
    private const PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4', '8.5'];

    public function register(Router $router): void
    {
        $router->add('POST', '/api/v1/bulk/vhosts', $this->vhosts(...));
    }

    private function vhosts(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');

        $action = $request->str('action') ?? '';
        if (!in_array($action, ['php_set', 'ssl_renew', 'suspend', 'unsuspend', 'malware_scan', 'backup'], true)) {
            throw new HttpException(422, 'invalid_action');
        }
        $ids = $request->body['vhost_ids'] ?? [];
        if (!is_array($ids) || $ids === [] || count($ids) > 200) {
            throw new HttpException(422, 'invalid_vhost_ids');
        }

        $php_version = null;
        if ($action === 'php_set') {
            $php_version = $request->str('php_version') ?? '';
            if (!in_array($php_version, self::PHP_VERSIONS, true)) {
                throw new HttpException(422, 'invalid_php_version');
            }
        }

        $results = [];
        foreach ($ids as $raw_id) {
            $vhost_id = (int) $raw_id;
            try {
                $vhost = $ctx->vhostOr404($vhost_id); // provjera vlasništva po svakom
                $results[$vhost_id] = ['domain' => $vhost['domain'], 'task_id' => $this->dispatch($action, $vhost, $php_version, $ctx)];
            } catch (HttpException $e) {
                $results[$vhost_id] = ['error' => $e->getMessage()];
            }
        }

        $this->app->audit->log($ctx->user_id, $ctx->email, 'bulk.vhosts', ['action' => $action, 'count' => count($ids)], $request->ip);
        Response::ok(['action' => $action, 'results' => $results], 202);
    }

    /** @param array<string, mixed> $vhost */
    private function dispatch(string $action, array $vhost, ?string $php_version, \ForgePanel\Web\Core\AuthContext $ctx): ?int
    {
        $vhost_id = (int) $vhost['id'];
        return match ($action) {
            'php_set' => $this->phpSet($vhost, $php_version, $ctx->user_id),
            'ssl_renew' => $this->app->tasks->enqueue('ssl.issue', [
                'hostnames' => [$vhost['domain'], 'www.' . $vhost['domain']],
                'contact_email' => $this->setting('acme_email', $ctx->email),
                'vhost_id' => $vhost_id,
            ], $ctx->user_id),
            'suspend', 'unsuspend' => $this->suspend($vhost, $action, $ctx->user_id),
            'malware_scan' => $this->malwareScan($vhost, $ctx->user_id),
            'backup' => $this->backup($vhost, $ctx->user_id),
            default => null,
        };
    }

    /** @param array<string, mixed> $vhost */
    private function phpSet(array $vhost, ?string $php_version, int $user_id): ?int
    {
        $this->app->agent->call('vhost.php_set', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'old_version' => $vhost['php_version'],
            'new_version' => $php_version,
        ], timeout_s: 60);
        $this->app->db->run('UPDATE vhosts SET php_version = ? WHERE id = ?', [$php_version, $vhost['id']]);
        return null;
    }

    /** @param array<string, mixed> $vhost */
    private function suspend(array $vhost, string $action, int $user_id): int
    {
        $task_id = $this->app->tasks->enqueue('vhost.suspend', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'php_version' => $vhost['php_version'],
            'action' => $action,
        ], $user_id);
        $this->app->db->run(
            'UPDATE vhosts SET status = ? WHERE id = ?',
            [$action === 'suspend' ? 'suspended' : 'active', $vhost['id']]
        );
        return $task_id;
    }

    /** @param array<string, mixed> $vhost */
    private function malwareScan(array $vhost, int $user_id): int
    {
        $this->app->db->run("INSERT INTO malware_scans (vhost_id, status) VALUES (?, 'running')", [$vhost['id']]);
        return $this->app->tasks->enqueue('malware.scan', [
            'scan_id' => $this->app->db->lastId(),
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
        ], $user_id);
    }

    /** @param array<string, mixed> $vhost */
    private function backup(array $vhost, int $user_id): int
    {
        $dest = $this->app->db->one("SELECT id FROM backup_destinations WHERE type = 'local' LIMIT 1");
        $dest_id = $dest['id'] ?? null;
        if ($dest_id === null) {
            $admin = $this->app->db->one("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin' LIMIT 1");
            $this->app->db->run(
                "INSERT INTO backup_destinations (owner_user_id, name, type, config) VALUES (?, 'Lokalni disk', 'local', '{\"path\":\"/var/backups/forgepanel\"}')",
                [$admin['id']]
            );
            $dest_id = $this->app->db->lastId();
        }
        $databases = array_column($this->app->db->all('SELECT name FROM db_databases WHERE subscription_id = ?', [$vhost['subscription_id']]), 'name');
        $this->app->db->run(
            "INSERT INTO backups (subscription_id, destination_id, type, path, status) VALUES (?, ?, 'full', '', 'running')",
            [$vhost['subscription_id'], $dest_id]
        );
        return $this->app->tasks->enqueue('backup.vhost_create', [
            'backup_id' => $this->app->db->lastId(),
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'databases' => $databases,
            'keep' => 7,
        ], $user_id);
    }
}
