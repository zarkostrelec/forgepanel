<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Crypto;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Multi-server temelj (Faza 5): jedan panel → agenti na više Ubuntu servera.
 * Registar servera; lokalni ('local') je uvijek prisutan. Daljinski agenti
 * se spajaju preko mTLS-a na forge-agentd socket-bridge (puna orkestracija
 * dolazi uz multi-server scheduling).
 */
final class ServersController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/servers', $this->index(...));
        $router->add('POST', '/api/v1/servers', $this->create(...));
        $router->add('DELETE', '/api/v1/servers/{id}', $this->delete(...));
        $router->add('GET', '/api/v1/servers/{id}/health', $this->health(...));
    }

    private function index(Request $request): never
    {
        $this->admin($request);
        $servers = $this->app->db->all('SELECT id, name, hostname, status, last_seen_at, created_at FROM servers ORDER BY id');
        // Lokalni server je implicitan ako registar prazan
        if ($servers === []) {
            $servers = [['id' => 0, 'name' => 'local', 'hostname' => $this->app->config->get('panel_fqdn', (string) gethostname()), 'status' => 'online', 'last_seen_at' => date('Y-m-d H:i:s'), 'created_at' => null]];
        }
        Response::ok($servers);
    }

    private function create(Request $request): never
    {
        $ctx = $this->admin($request);
        $name = trim($request->str('name') ?? '');
        $hostname = strtolower(trim($request->str('hostname') ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,32}$/', $name)) {
            throw new HttpException(422, 'invalid_name');
        }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $hostname)) {
            throw new HttpException(422, 'invalid_hostname');
        }
        // Enrollment token za daljinski agent (mTLS bootstrap)
        $token = 'fpsrv_' . bin2hex(random_bytes(24));
        $this->app->db->run(
            "INSERT INTO servers (name, hostname, enroll_token, status) VALUES (?, ?, ?, 'pending')",
            [$name, $hostname, (new Crypto($this->app->config))->encrypt($token)]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'server.add', ['name' => $name, 'hostname' => $hostname], $request->ip);
        Response::ok(['id' => $this->app->db->lastId(), 'enroll_token' => $token], 201);
    }

    private function delete(Request $request): never
    {
        $ctx = $this->admin($request);
        $deleted = $this->app->db->run('DELETE FROM servers WHERE id = ?', [(int) $request->param('id')])->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404, 'not_found');
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'server.remove', ['server_id' => $request->param('id')], $request->ip);
        Response::ok();
    }

    private function health(Request $request): never
    {
        $this->admin($request);
        $server = $this->app->db->one('SELECT * FROM servers WHERE id = ?', [(int) $request->param('id')]);
        if ($server === null) {
            throw new HttpException(404, 'not_found');
        }
        // Lokalni server: žive metrike kroz agent
        if ($server['hostname'] === $this->app->config->get('panel_fqdn', (string) gethostname())) {
            Response::ok(['local' => true, 'metrics' => $this->app->agent->call('system.metrics')]);
        }
        Response::ok(['local' => false, 'status' => $server['status'], 'last_seen_at' => $server['last_seen_at']]);
    }

    private function admin(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        $ctx = $this->ctx($request, 'servers:write');
        $ctx->requireRole('admin');
        return $ctx;
    }
}
