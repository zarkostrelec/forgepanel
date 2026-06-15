<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
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
        $router->add('GET', '/api/v1/monitoring/top', $this->top(...));
        $router->add('GET', '/api/v1/monitoring/alarms', $this->alarmsGet(...));
        $router->add('PUT', '/api/v1/monitoring/alarms', $this->alarmsPut(...));
        $router->add('POST', '/api/v1/monitoring/alarms/test', $this->alarmsTest(...));
    }

    /** Alarm konfiguracija (kanali + pragovi). Samo admin. */
    private function alarmsGet(Request $request): never
    {
        $this->ctx($request, 'monitoring:read')->requireRole('admin');
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = 'monitoring_alarms'");
        $cfg = $row === null ? null : json_decode((string) $row['value'], true);
        Response::ok(is_array($cfg) ? $cfg : [
            'enabled' => false,
            'channels' => ['email' => '', 'telegram' => ['bot_token' => '', 'chat_id' => ''], 'webhook' => ''],
            'thresholds' => ['cpu_pct' => 90, 'mem_pct' => 90, 'disk_pct' => 90],
        ]);
    }

    private function alarmsPut(Request $request): never
    {
        $ctx = $this->ctx($request, 'monitoring:write');
        $ctx->requireRole('admin');
        $b = $request->body;

        $email = trim((string) ($b['channels']['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'invalid_email');
        }
        $webhook = trim((string) ($b['channels']['webhook'] ?? ''));
        if ($webhook !== '' && !preg_match('#^https://[\w.-]+(?::\d+)?(/[\w./%?=&-]*)?$#', $webhook)) {
            throw new HttpException(422, 'invalid_webhook');
        }
        $bot = trim((string) ($b['channels']['telegram']['bot_token'] ?? ''));
        if ($bot !== '' && !preg_match('/^\d+:[\w-]+$/', $bot)) {
            throw new HttpException(422, 'invalid_telegram_token');
        }
        $clamp = static fn ($v) => max(0, min(100, (int) $v));
        $cfg = [
            'enabled' => (bool) ($b['enabled'] ?? false),
            'channels' => [
                'email' => $email,
                'telegram' => ['bot_token' => $bot, 'chat_id' => trim((string) ($b['channels']['telegram']['chat_id'] ?? ''))],
                'webhook' => $webhook,
            ],
            'thresholds' => [
                'cpu_pct' => $clamp($b['thresholds']['cpu_pct'] ?? 90),
                'mem_pct' => $clamp($b['thresholds']['mem_pct'] ?? 90),
                'disk_pct' => $clamp($b['thresholds']['disk_pct'] ?? 90),
            ],
        ];
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES ('monitoring_alarms', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [json_encode($cfg, JSON_UNESCAPED_SLASHES)]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'monitoring.alarms_update', ['enabled' => $cfg['enabled']], $request->ip);
        Response::ok($cfg);
    }

    /** Pošalji testnu poruku na konfigurirane kanale. */
    private function alarmsTest(Request $request): never
    {
        $this->ctx($request, 'monitoring:write')->requireRole('admin');
        Response::ok($this->app->agent->call('alarm.test', timeout_s: 30));
    }

    /** Top procesi po CPU-u (ps preko agenta). */
    private function top(Request $request): never
    {
        $this->ctx($request, 'monitoring:read')->requireRole('admin');
        Response::ok($this->app->agent->call('system.top'));
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
        // [rezolucija, minute unatrag] — poštuje retenciju (minute ~2h, sat ~7d, dan ~400d)
        $ranges = [
            '15m' => ['minute', 15],
            '1h' => ['minute', 60],
            '2h' => ['minute', 120],
            '6h' => ['hour', 360],
            '24h' => ['hour', 1440],
            '7d' => ['hour', 10080],
            '30d' => ['day', 43200],
        ];
        $range = (string) ($_GET['range'] ?? '1h');
        [$resolution, $minutes] = $ranges[$range] ?? $ranges['1h'];
        // resolution param i dalje podržan kao override (npr. dashboard)
        $res_override = (string) ($_GET['resolution'] ?? '');
        if (in_array($res_override, ['minute', 'hour', 'day'], true)) {
            $resolution = $res_override;
        }

        Response::ok($this->app->db->all(
            'SELECT value, ts FROM monitoring_metrics
             WHERE scope = ? AND metric = ? AND resolution = ? AND ts > DATE_SUB(NOW(), INTERVAL ? MINUTE)
             ORDER BY ts',
            [(string) $scope, (string) $metric, $resolution, $minutes]
        ));
    }
}
