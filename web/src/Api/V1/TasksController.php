<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;
use ForgePanel\Web\Core\Sse;

final class TasksController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/tasks', $this->index(...));
        $router->add('GET', '/api/v1/tasks/{id}', $this->show(...));
        $router->add('GET', '/api/v1/tasks/{id}/stream', $this->stream(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'tasks:read');
        Response::ok($ctx->isAdmin()
            ? $this->app->db->all('SELECT id, op, status, progress, created_at, finished_at FROM tasks ORDER BY id DESC LIMIT 100')
            : $this->app->db->all('SELECT id, op, status, progress, created_at, finished_at FROM tasks WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$ctx->user_id]));
    }

    private function show(Request $request): never
    {
        Response::ok($this->taskOr404($request));
    }

    private function stream(Request $request): never
    {
        $task = $this->taskOr404($request);
        Sse::streamTask($this->app->tasks, (int) $task['id']);
    }

    /** @return array<string, mixed> */
    private function taskOr404(Request $request): array
    {
        $ctx = $this->ctx($request, 'tasks:read');
        $task = $this->app->tasks->get((int) $request->param('id'));
        if ($task === null || (!$ctx->isAdmin() && (int) $task['user_id'] !== $ctx->user_id)) {
            throw new HttpException(404, 'not_found');
        }
        return $task;
    }
}
