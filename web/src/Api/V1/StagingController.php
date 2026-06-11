<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Universal staging — kloniranje BILO KOJE PHP stranice (ne samo WP). */
final class StagingController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/vhosts/{id}/staging', $this->index(...));
        $router->add('POST', '/api/v1/vhosts/{id}/staging', $this->create(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        Response::ok($this->app->db->all(
            'SELECT se.id, sv.domain AS staging_domain, se.created_at, se.last_sync
             FROM staging_envs se JOIN vhosts sv ON sv.id = se.staging_vhost_id
             WHERE se.source_vhost_id = ?',
            [$vhost['id']]
        ));
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $source = $ctx->vhostOr404((int) $request->param('id'));

        $prefix = strtolower(trim($request->str('subdomain') ?? 'staging'));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $prefix)) {
            throw new HttpException(422, 'invalid_subdomain');
        }
        $staging_domain = "$prefix.{$source['domain']}";
        if ($this->app->db->one('SELECT 1 FROM vhosts WHERE domain = ?', [$staging_domain]) !== null) {
            throw new HttpException(409, 'staging_exists');
        }

        // Staging vhost zapis
        $this->app->db->run(
            "INSERT INTO vhosts (domain, subscription_id, sys_user, php_version, docroot, status)
             VALUES (?, ?, 'pending', ?, ?, 'creating')",
            [$staging_domain, $source['subscription_id'], $source['php_version'], "/var/www/vhosts/$staging_domain/httpdocs"]
        );
        $staging_id = $this->app->db->lastId();
        $this->app->db->run('UPDATE vhosts SET sys_user = ? WHERE id = ?', ['vh_' . $staging_id, $staging_id]);

        // Baze subscriptiona → staging kopije (suffix _stg)
        $databases = [];
        foreach ($this->app->db->all('SELECT name FROM db_databases WHERE subscription_id = ?', [$source['subscription_id']]) as $db) {
            $staging_db = substr($db['name'], 0, 56) . '_stg';
            $databases[] = ['source' => $db['name'], 'staging' => $staging_db];
            $this->app->db->run(
                'INSERT INTO db_databases (subscription_id, name) VALUES (?, ?)',
                [$source['subscription_id'], $staging_db]
            );
        }

        $task_id = $this->app->tasks->enqueue('staging.clone', [
            'source_vhost_id' => (int) $source['id'],
            'staging_vhost_id' => $staging_id,
            'source_domain' => $source['domain'],
            'staging_domain' => $staging_domain,
            'php_version' => $source['php_version'],
            'databases' => $databases,
        ], $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'staging.create', ['source' => $source['domain'], 'staging' => $staging_domain], $request->ip);
        Response::ok(['staging_domain' => $staging_domain, 'task_id' => $task_id], 202);
    }
}
