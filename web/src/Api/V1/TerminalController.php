<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Web terminal. Admin: host shell. Klijent: sandboxiran container s vlastitim
 * vhostom (admin mora eksplicitno uključiti per-vhost). Poglavlje 14.10.
 */
final class TerminalController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('POST', '/api/v1/terminal/admin', $this->adminExec(...));
        $router->add('POST', '/api/v1/vhosts/{id}/terminal', $this->clientExec(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/terminal/toggle', $this->toggle(...));
    }

    private function adminExec(Request $request): never
    {
        $ctx = $this->ctx($request, 'terminal:admin');
        $ctx->requireRole('admin');
        $command = $request->str('command') ?? throw new HttpException(400, 'command_required');

        $result = $this->app->agent->call('terminal.exec', ['mode' => 'admin', 'command' => $command], timeout_s: 40);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'terminal.admin', ['command' => mb_substr($command, 0, 200)], $request->ip);
        Response::ok($result);
    }

    private function clientExec(Request $request): never
    {
        $ctx = $this->ctx($request, 'terminal:client');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        // Admin mora eksplicitno uključiti terminal za taj vhost
        $enabled = $this->app->db->one("SELECT value FROM settings WHERE `key` = ?", ['terminal_enabled_' . $vhost['id']]);
        if ($enabled === null && !$ctx->isAdmin()) {
            throw new HttpException(403, 'terminal_disabled');
        }

        $command = $request->str('command') ?? throw new HttpException(400, 'command_required');
        $result = $this->app->agent->call('terminal.exec', [
            'mode' => 'client',
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'command' => $command,
        ], timeout_s: 40);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'terminal.client', ['domain' => $vhost['domain'], 'command' => mb_substr($command, 0, 200)], $request->ip);
        Response::ok($result);
    }

    /** Admin uključuje/isključuje sandboxirani terminal za vhost. */
    private function toggle(Request $request): never
    {
        $ctx = $this->ctx($request, 'terminal:admin');
        $ctx->requireRole('admin');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $enabled = (bool) ($request->body['enabled'] ?? false);
        if ($enabled) {
            $this->app->db->run(
                "INSERT INTO settings (`key`, value) VALUES (?, '1') ON DUPLICATE KEY UPDATE value = '1'",
                ['terminal_enabled_' . $vhost['id']]
            );
        } else {
            $this->app->db->run("DELETE FROM settings WHERE `key` = ?", ['terminal_enabled_' . $vhost['id']]);
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'terminal.toggle', ['domain' => $vhost['domain'], 'enabled' => $enabled], $request->ip);
        Response::ok(['enabled' => $enabled]);
    }
}
