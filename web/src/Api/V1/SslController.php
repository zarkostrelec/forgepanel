<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class SslController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/ssl', $this->index(...));
        $router->add('POST', '/api/v1/vhosts/{id}/ssl/renew', $this->renew(...));
    }

    /** Pregled svih certifikata s istekom (filtrirano po vlasništvu). */
    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'ssl:read');
        if ($ctx->isAdmin()) {
            Response::ok($this->app->db->all('SELECT * FROM ssl_certs ORDER BY expires_at'));
        }
        if ($ctx->subscription_ids === []) {
            Response::ok([]);
        }
        $placeholders = implode(',', array_fill(0, count($ctx->subscription_ids), '?'));
        Response::ok($this->app->db->all(
            "SELECT c.* FROM ssl_certs c JOIN vhosts v ON v.id = c.vhost_id
             WHERE v.subscription_id IN ($placeholders) ORDER BY c.expires_at",
            $ctx->subscription_ids
        ));
    }

    private function renew(Request $request): never
    {
        $ctx = $this->ctx($request, 'ssl:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $task_id = $this->app->tasks->enqueue('ssl.issue', [
            'hostnames' => [$vhost['domain'], 'www.' . $vhost['domain']],
            'contact_email' => $this->app->config->get('acme_email', $ctx->email),
            'vhost_id' => (int) $vhost['id'],
        ], $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'ssl.renew', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }
}
