<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class CronController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/vhosts/{id}/cron', $this->index(...));
        $router->add('POST', '/api/v1/vhosts/{id}/cron', $this->create(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}/cron/{job_id}', $this->delete(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/cron/{job_id}/toggle', $this->toggle(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'cron:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        Response::ok($this->app->db->all(
            'SELECT id, schedule, command, enabled, last_run_at, last_exit_code FROM cron_jobs WHERE vhost_id = ? ORDER BY id',
            [$vhost['id']]
        ));
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'cron:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $schedule = trim($request->str('schedule') ?? '');
        $command = trim($request->str('command') ?? '');
        if (!preg_match('/^[0-9*,\/\- ]+$/', $schedule) || count(preg_split('/\s+/', $schedule) ?: []) !== 5) {
            throw new HttpException(422, 'invalid_schedule');
        }
        if ($command === '' || strlen($command) > 500 || preg_match('/[%\x00-\x1f\x7f]/', $command)) {
            throw new HttpException(422, 'invalid_command');
        }

        $this->app->db->run(
            'INSERT INTO cron_jobs (vhost_id, schedule, command) VALUES (?, ?, ?)',
            [$vhost['id'], $schedule, $command]
        );
        $job_id = $this->app->db->lastId();
        try {
            $this->sync($vhost);
        } catch (\Throwable $e) {
            // Agent nije primijenio promjenu — makni red da DB i /etc/cron.d ostanu usklađeni
            $this->app->db->run('DELETE FROM cron_jobs WHERE id = ?', [$job_id]);
            throw $e;
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'cron.create', ['domain' => $vhost['domain'], 'schedule' => $schedule], $request->ip);
        Response::ok(['id' => $job_id], 201);
    }

    private function delete(Request $request): never
    {
        $ctx = $this->ctx($request, 'cron:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $deleted = $this->app->db->run(
            'DELETE FROM cron_jobs WHERE id = ? AND vhost_id = ?',
            [(int) $request->param('job_id'), $vhost['id']]
        )->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404, 'not_found');
        }
        $this->sync($vhost);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'cron.delete', ['domain' => $vhost['domain']], $request->ip);
        Response::ok();
    }

    private function toggle(Request $request): never
    {
        $ctx = $this->ctx($request, 'cron:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $updated = $this->app->db->run(
            'UPDATE cron_jobs SET enabled = 1 - enabled WHERE id = ? AND vhost_id = ?',
            [(int) $request->param('job_id'), $vhost['id']]
        )->rowCount();
        if ($updated === 0) {
            throw new HttpException(404, 'not_found');
        }
        $this->sync($vhost);
        Response::ok();
    }

    /** Sinkronizira aktivne jobove vhosta u /etc/cron.d kroz agent. @param array<string, mixed> $vhost */
    private function sync(array $vhost): void
    {
        $jobs = $this->app->db->all(
            'SELECT schedule, command FROM cron_jobs WHERE vhost_id = ? AND enabled = 1',
            [$vhost['id']]
        );
        $this->app->agent->call('cron.sync', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'jobs' => $jobs,
        ]);
    }
}
