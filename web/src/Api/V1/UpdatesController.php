<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Update orkestrator — samo admin. */
final class UpdatesController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/updates', $this->index(...));
        $router->add('POST', '/api/v1/updates/scan', $this->scan(...));
        $router->add('POST', '/api/v1/updates/{component}/apply', $this->apply(...));
        $router->add('PUT', '/api/v1/updates/{component}/policy', $this->policy(...));
        $router->add('GET', '/api/v1/updates/history', $this->history(...));
    }

    private function index(Request $request): never
    {
        $this->admin($request);
        Response::ok($this->app->db->all(
            'SELECT c.id, c.name, c.current_version, c.available_version, c.repo_suite,
                    c.status, c.security_update,
                    p.mode, p.window_start, p.window_end, p.window_days
             FROM components c LEFT JOIN update_policies p ON p.component_id = c.id
             ORDER BY c.name'
        ));
    }

    private function scan(Request $request): never
    {
        $ctx = $this->admin($request);
        $task_id = $this->app->tasks->enqueue('updates.scan', [], $ctx->user_id);
        Response::ok(['task_id' => $task_id], 202);
    }

    private function apply(Request $request): never
    {
        $ctx = $this->admin($request);
        $component = $this->componentOr404($request);
        $task_id = $this->app->tasks->enqueue('updates.apply', ['component' => $component['name']], $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'updates.apply', ['component' => $component['name']], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    private function policy(Request $request): never
    {
        $ctx = $this->admin($request);
        $component = $this->componentOr404($request);

        $mode = $request->str('mode') ?? '';
        if (!in_array($mode, ['auto_all', 'auto_security_only', 'manual', 'frozen'], true)) {
            throw new HttpException(422, 'invalid_mode');
        }
        $window_start = $request->str('window_start', '03:00:00');
        $window_end = $request->str('window_end', '05:00:00');
        foreach ([$window_start, $window_end] as $time) {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $time)) {
                throw new HttpException(422, 'invalid_window');
            }
        }
        $days = $request->body['window_days'] ?? [7];
        if (!is_array($days) || $days === [] || array_diff($days, range(1, 7)) !== []) {
            throw new HttpException(422, 'invalid_days');
        }

        $this->app->db->run(
            'UPDATE update_policies SET mode = ?, window_start = ?, window_end = ?, window_days = ?
             WHERE component_id = ?',
            [$mode, $window_start, $window_end, json_encode(array_values($days)), $component['id']]
        );
        // Odmrzavanje: mode != frozen vraća komponentu u installed
        if ($mode !== 'frozen' && $component['status'] === 'frozen') {
            $this->app->db->run("UPDATE components SET status = 'installed' WHERE id = ?", [$component['id']]);
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'updates.policy', ['component' => $component['name'], 'mode' => $mode], $request->ip);
        Response::ok();
    }

    private function history(Request $request): never
    {
        $this->admin($request);
        Response::ok($this->app->db->all(
            'SELECT cu.id, c.name, cu.from_version, cu.to_version, cu.status, cu.created_at
             FROM component_updates cu JOIN components c ON c.id = cu.component_id
             ORDER BY cu.id DESC LIMIT 100'
        ));
    }

    private function admin(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        $ctx = $this->ctx($request, 'updates:write');
        $ctx->requireRole('admin');
        return $ctx;
    }

    /** @return array<string, mixed> */
    private function componentOr404(Request $request): array
    {
        $name = $request->param('component');
        if (!preg_match('/^[a-z0-9.]{2,32}$/', $name)) {
            throw new HttpException(404, 'not_found');
        }
        return $this->app->db->one('SELECT * FROM components WHERE name = ?', [$name])
            ?? throw new HttpException(404, 'not_found');
    }
}
