<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Malware skener + karantena (Imunify360 ekvivalent, ugrađeno). */
final class SecurityController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/security/scans', $this->scans(...));
        $router->add('POST', '/api/v1/vhosts/{id}/security/scan', $this->scan(...));
        $router->add('GET', '/api/v1/security/quarantine', $this->quarantine(...));
        $router->add('POST', '/api/v1/security/quarantine/{qid}/restore', $this->restore(...));
        $router->add('POST', '/api/v1/security/quarantine/{qid}/delete', $this->purge(...));
    }

    private function scans(Request $request): never
    {
        $ctx = $this->ctx($request, 'security:read');
        $scans = $ctx->isAdmin()
            ? $this->app->db->all('SELECT s.*, v.domain FROM malware_scans s LEFT JOIN vhosts v ON v.id = s.vhost_id ORDER BY s.id DESC LIMIT 100')
            : ($ctx->subscription_ids === [] ? [] : $this->app->db->all(
                'SELECT s.*, v.domain FROM malware_scans s JOIN vhosts v ON v.id = s.vhost_id
                 WHERE v.subscription_id IN (' . implode(',', array_fill(0, count($ctx->subscription_ids), '?')) . ')
                 ORDER BY s.id DESC LIMIT 100',
                $ctx->subscription_ids
            ));
        Response::ok($scans);
    }

    private function scan(Request $request): never
    {
        $ctx = $this->ctx($request, 'security:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $this->app->db->run(
            "INSERT INTO malware_scans (vhost_id, status) VALUES (?, 'running')",
            [$vhost['id']]
        );
        $scan_id = $this->app->db->lastId();
        $task_id = $this->app->tasks->enqueue('malware.scan', [
            'scan_id' => $scan_id,
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'auto_quarantine' => (bool) ($request->body['auto_quarantine'] ?? false),
        ], $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'security.scan', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['scan_id' => $scan_id, 'task_id' => $task_id], 202);
    }

    private function quarantine(Request $request): never
    {
        $ctx = $this->ctx($request, 'security:read');
        $items = $ctx->isAdmin()
            ? $this->app->db->all('SELECT q.*, v.domain FROM quarantine_items q JOIN vhosts v ON v.id = q.vhost_id ORDER BY q.id DESC LIMIT 200')
            : ($ctx->subscription_ids === [] ? [] : $this->app->db->all(
                'SELECT q.*, v.domain FROM quarantine_items q JOIN vhosts v ON v.id = q.vhost_id
                 WHERE v.subscription_id IN (' . implode(',', array_fill(0, count($ctx->subscription_ids), '?')) . ')
                 ORDER BY q.id DESC LIMIT 200',
                $ctx->subscription_ids
            ));
        Response::ok($items);
    }

    private function restore(Request $request): never
    {
        $this->quarantineAction($request, 'restore');
    }

    private function purge(Request $request): never
    {
        $this->quarantineAction($request, 'delete');
    }

    private function quarantineAction(Request $request, string $action): never
    {
        $ctx = $this->ctx($request, 'security:write');
        $item = $this->app->db->one('SELECT * FROM quarantine_items WHERE id = ?', [(int) $request->param('qid')])
            ?? throw new HttpException(404, 'not_found');
        $vhost = $ctx->vhostOr404((int) $item['vhost_id']);

        $this->app->agent->call('quarantine.action', [
            'item_id' => (int) $item['id'],
            'vhost_id' => (int) $item['vhost_id'],
            'original_path' => $item['path'],
            'action' => $action,
        ], timeout_s: 60);

        $this->app->db->run('DELETE FROM quarantine_items WHERE id = ?', [$item['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, "quarantine.$action", ['path' => $item['path'], 'domain' => $vhost['domain']], $request->ip);
        Response::ok();
    }
}
