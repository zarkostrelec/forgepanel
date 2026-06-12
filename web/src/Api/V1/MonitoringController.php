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
        $panel_fpm = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm';
        // Servisi koje prikazujemo (status + potrošnja iz monitoring_metrics)
        $list = ['nginx', 'apache2', $panel_fpm, 'mariadb', 'mysql', 'postfix', 'dovecot',
            'rspamd', 'named', 'proftpd', 'redis-server', 'docker', 'fail2ban', 'forge-agentd'];

        // Zadnja izmjerena potrošnja po servisu (scope service:<name>)
        $usage = [];
        foreach ($this->app->db->all(
            "SELECT scope, metric, value FROM monitoring_metrics m
             WHERE scope LIKE 'service:%' AND metric IN ('cpu_pct','mem_bytes') AND resolution='minute'
               AND ts = (SELECT MAX(ts) FROM monitoring_metrics WHERE scope=m.scope AND metric=m.metric)"
        ) as $row) {
            $usage[substr((string) $row['scope'], 8)][$row['metric']] = (float) $row['value'];
        }

        $services = [];
        foreach ($list as $service) {
            try {
                $status = $this->app->agent->call('service.status', ['service' => $service]);
            } catch (\Throwable) {
                $status = ['ActiveState' => 'unknown'];
            }
            // servis koji uopće nije instaliran (inactive + bez load patha) preskačemo
            if (($status['ActiveState'] ?? '') === 'inactive' && ($status['LoadState'] ?? '') === 'not-found') {
                continue;
            }
            $status['cpu_pct'] = $usage[$service]['cpu_pct'] ?? null;
            // mem iz metrika; fallback na trenutni MemoryCurrent iz systemctl show
            $mem_current = isset($status['MemoryCurrent']) && ctype_digit((string) $status['MemoryCurrent'])
                ? (float) $status['MemoryCurrent'] : null;
            $status['mem_bytes'] = $usage[$service]['mem_bytes'] ?? $mem_current;
            $services[$service] = $status;
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
        $metric = preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) ($_GET['metric'] ?? '')) ? $_GET['metric'] : 'cpu_load1';

        // Period → rezolucija (poštuje retenciju: minute ~2h, sat ~7d, dan ~400d)
        $ranges = [
            '2h' => ['minute', 2],
            '24h' => ['hour', 24],
            '7d' => ['hour', 168],
            '30d' => ['day', 720],
        ];
        $range = (string) ($_GET['range'] ?? '2h');
        [$resolution, $hours] = $ranges[$range] ?? $ranges['2h'];
        // resolution param i dalje podržan kao override (npr. dashboard)
        $res_override = (string) ($_GET['resolution'] ?? '');
        if (in_array($res_override, ['minute', 'hour', 'day'], true)) {
            $resolution = $res_override;
        }

        Response::ok($this->app->db->all(
            'SELECT value, ts FROM monitoring_metrics
             WHERE scope = ? AND metric = ? AND resolution = ? AND ts > DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY ts',
            [(string) $scope, (string) $metric, $resolution, $hours]
        ));
    }
}
