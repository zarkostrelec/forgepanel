<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Config time-machine — samo admin. */
final class ConfigHistoryController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/config-history', $this->list(...));
        $router->add('GET', '/api/v1/config-history/{hash}/diff', $this->diff(...));
        $router->add('POST', '/api/v1/config-history/{hash}/restore', $this->restore(...));
    }

    private function list(Request $request): never
    {
        $this->admin($request);
        Response::ok($this->app->agent->call('config.history', ['action' => 'list'], timeout_s: 30));
    }

    private function diff(Request $request): never
    {
        $this->admin($request);
        Response::ok($this->app->agent->call('config.history', [
            'action' => 'diff',
            'hash' => $this->hash($request),
        ], timeout_s: 30));
    }

    private function restore(Request $request): never
    {
        $ctx = $this->admin($request);
        $hash = $this->hash($request);
        $this->app->agent->call('config.history', [
            'action' => 'restore',
            'hash' => $hash,
            'changed_by' => $ctx->email,
        ], timeout_s: 120);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'config.restore', ['hash' => $hash], $request->ip);
        Response::ok();
    }

    private function hash(Request $request): string
    {
        $hash = $request->param('hash');
        if (!preg_match('/^[0-9a-f]{7,40}$/', $hash)) {
            throw new HttpException(404, 'not_found');
        }
        return $hash;
    }

    private function admin(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        $ctx = $this->ctx($request, 'config:read');
        $ctx->requireRole('admin');
        return $ctx;
    }
}
