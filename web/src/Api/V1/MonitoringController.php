<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class MonitoringController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/monitoring/now', $this->now(...));
        $router->add('GET', '/api/v1/monitoring/services', $this->services(...));
        $router->add('GET', '/api/v1/monitoring/history', $this->history(...));
    }

    private function now(Request $request): never
    {
        $this->ctx($request, 'monitoring:read')->requireRole('admin', 'reseller');
        Response::ok($this->app->agent->call('system.metrics'));
    }

    private function services(Request $request): never
    {
        $this->ctx($request, 'monitoring:read')->requireRole('admin');
        $services = [];
        foreach (['nginx', 'mariadb', 'php8.4-fpm', 'fail2ban'] as $service) {
            try {
                $services[$service] = $this->app->agent->call('service.status', ['service' => $service]);
            } catch (\Throwable) {
                $services[$service] = ['ActiveState' => 'unknown'];
            }
        }
        Response::ok($services);
    }

    private function history(Request $request): never
    {
        $ctx = $this->ctx($request, 'monitoring:read');
        $scope = $_GET['scope'] ?? 'server';
        if (!$ctx->isAdmin() && !preg_match('/^vhost:\d+$/', (string) $scope)) {
            $scope = 'server'; // klijent dobiva samo agregat servera ili svoje vhostove
        }
        $metric = preg_match('/^[a-z_]{1,32}$/', (string) ($_GET['metric'] ?? '')) ? $_GET['metric'] : 'cpu_pct';
        $resolution = in_array($_GET['resolution'] ?? 'minute', ['minute', 'hour', 'day'], true)
            ? $_GET['resolution'] : 'minute';

        Response::ok($this->app->db->all(
            'SELECT value, ts FROM monitoring_metrics
             WHERE scope = ? AND metric = ? AND resolution = ? AND ts > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY ts',
            [(string) $scope, (string) $metric, $resolution]
        ));
    }
}
