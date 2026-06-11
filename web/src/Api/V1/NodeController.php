<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Node.js runtime — Node app per vhost (systemd user-servis + nginx proxy). */
final class NodeController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('POST', '/api/v1/vhosts/{id}/node', $this->create(...));
        $router->add('POST', '/api/v1/vhosts/{id}/node/action', $this->action(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}/node', $this->remove(...));
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $entry = $request->str('entry', 'index.js');
        if (!preg_match('#^[a-zA-Z0-9_./-]{1,128}$#', (string) $entry) || str_contains((string) $entry, '..')) {
            throw new HttpException(422, 'invalid_entry');
        }
        $task_id = $this->app->tasks->enqueue('node.app', [
            'vhost_id' => (int) $vhost['id'], 'domain' => $vhost['domain'], 'action' => 'create', 'entry' => $entry,
        ], $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'node.create', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    private function action(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $action = $request->str('action') ?? '';
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw new HttpException(422, 'invalid_action');
        }
        $this->app->agent->call('node.app', [
            'vhost_id' => (int) $vhost['id'], 'domain' => $vhost['domain'], 'action' => $action,
        ], timeout_s: 60);
        Response::ok();
    }

    private function remove(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $this->app->agent->call('node.app', [
            'vhost_id' => (int) $vhost['id'], 'domain' => $vhost['domain'], 'action' => 'remove',
        ], timeout_s: 60);
        Response::ok();
    }
}
