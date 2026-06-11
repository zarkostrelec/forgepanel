<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

/**
 * Periodični poslovi agenta — izvršavaju se unutar glavne petlje:
 * - prikupljanje monitoring metrika (minuta) + agregacija minuta→sat→dan s retencijom
 * - AutoSSL obnova: certifikati < 30 dana do isteka → ssl.issue task
 * - čišćenje isteklih sessiona i starih notifikacija
 */
final class Scheduler
{
    /** @var array<string, int> */
    private array $last_run = [];

    /** @var array<int, int> probe_id => zadnja provjera (unix ts) */
    private array $probe_last = [];

    public function __construct(
        private readonly Db $db,
        private readonly Config $config,
    ) {
    }

    public function tick(): void
    {
        $this->every('metrics', 60, $this->collectMetrics(...));
        $this->every('aggregate', 3600, $this->aggregate(...));
        $this->every('ssl_renew', 6 * 3600, $this->enqueueSslRenewals(...));
        $this->every('cleanup', 3600, $this->cleanup(...));
        $this->every('uptime', 30, $this->runUptimeProbes(...));
    }

    /**
     * Eksterni uptime monitoring — HTTP/HTTPS/TCP probe za svaki vhost,
     * response time, eventi i notifikacija vlasniku na promjenu stanja.
     */
    private function runUptimeProbes(): void
    {
        $now = time();
        $probes = $this->db->all('SELECT * FROM uptime_probes');
        foreach ($probes as $probe) {
            $probe_id = (int) $probe['id'];
            if (($this->probe_last[$probe_id] ?? 0) + (int) $probe['interval_s'] > $now) {
                continue;
            }
            $this->probe_last[$probe_id] = $now;

            [$status, $response_ms, $detail] = $this->probe((string) $probe['type'], (string) $probe['target']);

            $this->db->run(
                'UPDATE uptime_probes SET last_status = ?, response_ms = ? WHERE id = ?',
                [$status, $response_ms, $probe_id]
            );

            if ($status !== $probe['last_status'] && $probe['last_status'] !== 'unknown') {
                $this->db->run(
                    'INSERT INTO uptime_events (probe_id, event, detail) VALUES (?, ?, ?)',
                    [$probe_id, $status, $detail]
                );
                $owner = $this->db->one(
                    'SELECT s.user_id FROM vhosts v JOIN subscriptions s ON s.id = v.subscription_id WHERE v.id = ?',
                    [$probe['vhost_id']]
                );
                if ($owner !== null) {
                    $this->db->run(
                        'INSERT INTO notifications (user_id, severity, title, body) VALUES (?, ?, ?, ?)',
                        [
                            $owner['user_id'],
                            $status === 'down' ? 'error' : 'info',
                            ($status === 'down' ? 'Stranica nedostupna: ' : 'Stranica ponovno dostupna: ') . $probe['target'],
                            $detail,
                        ]
                    );
                }
            }
        }
    }

    /** @return array{0: string, 1: ?int, 2: ?string} [status, response_ms, detail] */
    private function probe(string $type, string $target): array
    {
        $start = microtime(true);
        if ($type === 'tcp') {
            [$host, $port] = array_pad(explode(':', $target, 2), 2, '443');
            $socket = @fsockopen($host, (int) $port, $errno, $errstr, 10);
            $ms = (int) round((microtime(true) - $start) * 1000);
            if ($socket === false) {
                return ['down', null, "tcp: $errstr"];
            }
            fclose($socket);
            return ['up', $ms, null];
        }

        $url = ($type === 'http' ? 'http://' : 'https://') . $target . '/';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => false, // dostupnost, ne validnost certa (SSL expiry prati ssl modul)
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'ForgePanel-Uptime/1.0',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($code >= 200 && $code < 500 && $code !== 0) {
            return ['up', $ms, null];
        }
        return ['down', null, $code > 0 ? "HTTP $code" : $error];
    }

    private function every(string $key, int $interval_s, \Closure $job): void
    {
        $now = time();
        if (($this->last_run[$key] ?? 0) + $interval_s > $now) {
            return;
        }
        $this->last_run[$key] = $now;
        try {
            $job();
        } catch (\Throwable $e) {
            error_log("forge-agentd scheduler [$key]: " . $e->getMessage());
        }
    }

    private function collectMetrics(): void
    {
        $meminfo = [];
        foreach (explode("\n", (string) file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $meminfo[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $load = sys_getloadavg() ?: [0.0];
        $rows = [
            ['cpu_load1', $load[0]],
            ['mem_used_bytes', (float) (($meminfo['MemTotal'] ?? 0) - ($meminfo['MemAvailable'] ?? 0))],
            ['disk_used_bytes', (float) disk_total_space('/') - (float) disk_free_space('/')],
        ];
        foreach ($rows as [$metric, $value]) {
            $this->db->run(
                "INSERT INTO monitoring_metrics (scope, metric, resolution, value, ts) VALUES ('server', ?, 'minute', ?, NOW())",
                [$metric, $value]
            );
        }
    }

    private function aggregate(): void
    {
        // minuta → sat (sve starije od 2 h), pa obriši minutne
        $this->db->run(
            "INSERT INTO monitoring_metrics (scope, metric, resolution, value, ts)
             SELECT scope, metric, 'hour', AVG(value), DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')
             FROM monitoring_metrics
             WHERE resolution = 'minute' AND ts < DATE_SUB(NOW(), INTERVAL 2 HOUR)
             GROUP BY scope, metric, DATE_FORMAT(ts, '%Y-%m-%d %H:00:00')"
        );
        $this->db->run(
            "DELETE FROM monitoring_metrics WHERE resolution = 'minute' AND ts < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
        );

        // sat → dan (sve starije od 7 dana), pa obriši satne
        $this->db->run(
            "INSERT INTO monitoring_metrics (scope, metric, resolution, value, ts)
             SELECT scope, metric, 'day', AVG(value), DATE(ts)
             FROM monitoring_metrics
             WHERE resolution = 'hour' AND ts < DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY scope, metric, DATE(ts)"
        );
        $this->db->run(
            "DELETE FROM monitoring_metrics WHERE resolution = 'hour' AND ts < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $this->db->run(
            "DELETE FROM monitoring_metrics WHERE resolution = 'day' AND ts < DATE_SUB(NOW(), INTERVAL 400 DAY)"
        );
    }

    private function enqueueSslRenewals(): void
    {
        $contact = $this->config->get('acme_email', 'admin@localhost.localdomain');
        $due = $this->db->all(
            "SELECT c.id, c.hostname, c.vhost_id
             FROM ssl_certs c
             WHERE c.auto_renew = 1 AND c.status = 'active'
               AND c.expires_at < DATE_ADD(NOW(), INTERVAL 30 DAY)"
        );
        foreach ($due as $cert) {
            $already = $this->db->one(
                "SELECT 1 FROM tasks
                 WHERE op = 'ssl.issue' AND status IN ('pending', 'running')
                   AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.hostnames[0]')) = ?",
                [$cert['hostname']]
            );
            if ($already !== null) {
                continue;
            }
            $this->db->run(
                'INSERT INTO tasks (op, params) VALUES (?, ?)',
                ['ssl.issue', json_encode([
                    'hostnames' => [$cert['hostname'], 'www.' . $cert['hostname']],
                    'contact_email' => $contact,
                    'vhost_id' => $cert['vhost_id'] === null ? null : (int) $cert['vhost_id'],
                ], JSON_UNESCAPED_SLASHES)]
            );
        }
    }

    private function cleanup(): void
    {
        $this->db->run('DELETE FROM sessions WHERE expires_at < NOW()');
        $this->db->run('DELETE FROM notifications WHERE read_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
        $this->db->run("DELETE FROM tasks WHERE status IN ('done', 'failed', 'cancelled') AND finished_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }
}
