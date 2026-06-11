<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\AuthContext;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class DockerController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/docker', $this->index(...));
        $router->add('POST', '/api/v1/docker', $this->create(...));
        $router->add('POST', '/api/v1/docker/{id}/action', $this->action(...));
        $router->add('GET', '/api/v1/docker/{id}/logs', $this->logs(...));
        $router->add('POST', '/api/v1/docker/{id}/proxy', $this->proxy(...));
        $router->add('DELETE', '/api/v1/docker/{id}', $this->remove(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'docker:read');
        $rows = $ctx->isAdmin()
            ? $this->app->db->all('SELECT * FROM docker_containers ORDER BY name')
            : ($ctx->subscription_ids === [] ? [] : $this->app->db->all(
                'SELECT * FROM docker_containers WHERE subscription_id IN ('
                . implode(',', array_fill(0, count($ctx->subscription_ids), '?')) . ') ORDER BY name',
                $ctx->subscription_ids
            ));
        // Živi status iz Dockera
        foreach ($rows as &$row) {
            try {
                $status = $this->app->agent->call('docker.action', ['name' => $row['name'], 'action' => 'status']);
                $row['state'] = $status['state'] ?? 'unknown';
            } catch (\Throwable) {
                $row['state'] = 'unknown';
            }
        }
        Response::ok($rows);
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'docker:write');

        $subscription_id = $request->int('subscription_id') ?? ($ctx->subscription_ids[0] ?? null);
        if ($subscription_id === null) {
            throw new HttpException(422, 'subscription_required');
        }
        $ctx->requireSubscription($subscription_id);

        $short = strtolower(trim($request->str('name') ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $short)) {
            throw new HttpException(422, 'invalid_name');
        }
        $name = "fp-{$subscription_id}-{$short}";
        $image = trim($request->str('image') ?? '');
        if (!preg_match('#^[a-z0-9][a-z0-9._/:@-]{1,200}$#', $image)) {
            throw new HttpException(422, 'invalid_image');
        }
        if ($this->app->db->one('SELECT 1 FROM docker_containers WHERE name = ?', [$name]) !== null) {
            throw new HttpException(409, 'container_exists');
        }

        $ports = [];
        foreach ((array) ($request->body['ports'] ?? []) as $port) {
            $ports[] = ['host' => (int) ($port['host'] ?? 0), 'container' => (int) ($port['container'] ?? 0)];
        }
        $env = (array) ($request->body['env'] ?? []);
        $config = [
            'name' => $name,
            'image' => $image,
            'ports' => $ports,
            'env' => $env,
            'restart_policy' => $request->str('restart_policy', 'unless-stopped'),
            'memory_bytes' => $request->int('memory_bytes', 268435456),
            'cpus' => (float) ($request->body['cpus'] ?? 1.0),
        ];

        $this->app->db->run(
            "INSERT INTO docker_containers (subscription_id, container_id, name, image, config) VALUES (?, '', ?, ?, ?)",
            [$subscription_id, $name, $image, json_encode($config, JSON_UNESCAPED_SLASHES)]
        );
        $row_id = $this->app->db->lastId();
        $task_id = $this->app->tasks->enqueue('docker.create', $config, $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'docker.create', ['name' => $name, 'image' => $image], $request->ip);
        Response::ok(['id' => $row_id, 'name' => $name, 'task_id' => $task_id], 202);
    }

    private function action(Request $request): never
    {
        $ctx = $this->ctx($request, 'docker:write');
        $row = $this->containerOr404($ctx, (int) $request->param('id'));
        $action = $request->str('action') ?? '';
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new HttpException(422, 'invalid_action');
        }
        $this->app->agent->call('docker.action', ['name' => $row['name'], 'action' => $action], timeout_s: 120);
        $this->app->audit->log($ctx->user_id, $ctx->email, "docker.$action", ['name' => $row['name']], $request->ip);
        Response::ok();
    }

    private function logs(Request $request): never
    {
        $ctx = $this->ctx($request, 'docker:read');
        $row = $this->containerOr404($ctx, (int) $request->param('id'));
        Response::ok($this->app->agent->call('docker.action', [
            'name' => $row['name'],
            'action' => 'logs',
            'tail' => (int) ($request->query('tail') ?? 200),
        ], timeout_s: 60));
    }

    /** Mapiranje domena → container port jednim klikom. */
    private function proxy(Request $request): never
    {
        $ctx = $this->ctx($request, 'docker:write');
        $row = $this->containerOr404($ctx, (int) $request->param('id'));
        $vhost = $ctx->vhostOr404($request->int('vhost_id') ?? 0);
        $port = $request->int('port') ?? 0;

        $config = json_decode((string) $row['config'], true) ?: [];
        $host_ports = array_column($config['ports'] ?? [], 'host');
        if (!in_array($port, $host_ports, true)) {
            throw new HttpException(422, 'port_not_mapped_on_container');
        }

        $this->app->agent->call('docker.proxy_map', ['domain' => $vhost['domain'], 'port' => $port], timeout_s: 60);
        $this->app->db->run(
            'UPDATE docker_containers SET proxy_vhost_id = ?, proxy_port = ? WHERE id = ?',
            [$vhost['id'], $port, $row['id']]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'docker.proxy_map', ['name' => $row['name'], 'domain' => $vhost['domain'], 'port' => $port], $request->ip);
        Response::ok(['domain' => $vhost['domain'], 'port' => $port]);
    }

    private function remove(Request $request): never
    {
        $ctx = $this->ctx($request, 'docker:write');
        $row = $this->containerOr404($ctx, (int) $request->param('id'));
        $this->app->agent->call('docker.action', ['name' => $row['name'], 'action' => 'rm'], timeout_s: 120);
        $this->app->db->run('DELETE FROM docker_containers WHERE id = ?', [$row['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'docker.rm', ['name' => $row['name']], $request->ip);
        Response::ok();
    }

    /** @return array<string, mixed> */
    private function containerOr404(AuthContext $ctx, int $id): array
    {
        $row = $this->app->db->one('SELECT * FROM docker_containers WHERE id = ?', [$id]);
        if ($row === null) {
            throw new HttpException(404, 'not_found');
        }
        $ctx->requireSubscription((int) $row['subscription_id']);
        return $row;
    }
}
