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

    /** Delta-uzorci za stope (rate): metrika => [ts(float), kumulativna vrijednost]. */
    /** @var array<string, array{0: float, 1: float}> */
    private array $rate_last = [];

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
        $this->every('panel_cert', 900, $this->ensurePanelCert(...));
        $this->every('cleanup', 3600, $this->cleanup(...));
        $this->every('uptime', 30, $this->runUptimeProbes(...));
        $this->every('updates_scan', 4 * 3600, $this->enqueueUpdatesScan(...));
        $this->every('updates_auto', 900, $this->enqueueAutoUpdates(...));
        $this->every('suite_watch', 24 * 3600, $this->watchSuites(...));
        $this->every('vhost_stats', 1800, $this->refreshVhostStats(...));
        $this->every('panel_update', 4 * 3600, $this->checkPanelUpdate(...));
        $this->every('license_check', 6 * 3600, $this->checkLicense(...));
        $this->every('rbl_monitor', 24 * 3600, $this->checkRbls(...));
        $this->every('dmarc_ingest', 3600, $this->ingestDmarc(...));
        $this->every('alarms', 60, $this->checkAlarms(...));
    }

    /** @var array<string, int> alarm key => unix ts zadnjeg slanja (re-alarm dedup) */
    private array $alarm_fired = [];

    /**
     * Pragovi resursa (CPU/RAM/disk) iz settings.monitoring_alarms. Breach →
     * notifikacija adminima + vanjski kanali. Re-alarm najviše jednom na sat;
     * oporavak (ispod praga) resetira stanje i šalje "vraćeno u normalu".
     */
    private function checkAlarms(): void
    {
        $cfg = System\Notifier::config($this->db);
        if (($cfg['enabled'] ?? false) !== true) {
            return;
        }
        $thr = is_array($cfg['thresholds'] ?? null) ? $cfg['thresholds'] : [];

        $meminfo = [];
        foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $meminfo[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $mem_total = (float) ($meminfo['MemTotal'] ?? 0);
        $mem_used = $mem_total - (float) ($meminfo['MemAvailable'] ?? 0);
        $disk_total = (float) @disk_total_space('/');
        $disk_used = $disk_total - (float) @disk_free_space('/');
        $cpu = $this->db->one(
            "SELECT value FROM monitoring_metrics WHERE scope='server' AND metric='cpu_pct' AND resolution='minute' ORDER BY ts DESC LIMIT 1"
        );

        $checks = [
            'cpu' => ['pct' => $cpu === null ? null : (float) $cpu['value'], 'limit' => (float) ($thr['cpu_pct'] ?? 0), 'label' => 'CPU'],
            'mem' => ['pct' => $mem_total > 0 ? $mem_used / $mem_total * 100 : null, 'limit' => (float) ($thr['mem_pct'] ?? 0), 'label' => 'RAM'],
            'disk' => ['pct' => $disk_total > 0 ? $disk_used / $disk_total * 100 : null, 'limit' => (float) ($thr['disk_pct'] ?? 0), 'label' => 'Disk'],
        ];
        foreach ($checks as $key => $c) {
            if ($c['pct'] === null || $c['limit'] <= 0) {
                continue;
            }
            if ($c['pct'] >= $c['limit']) {
                if (($this->alarm_fired[$key] ?? 0) + 3600 > time()) {
                    continue; // već alarmirano u zadnjih sat
                }
                $this->alarm_fired[$key] = time();
                $pct = round($c['pct']);
                $this->notifyAdmins('error', "{$c['label']} alarm: {$pct}%", "Prag {$c['limit']}% premašen ({$pct}%).", true);
            } elseif (isset($this->alarm_fired[$key])) {
                unset($this->alarm_fired[$key]);
                $this->notifyAdmins('info', "{$c['label']} vraćen u normalu", round($c['pct']) . '%', true);
            }
        }
    }

    /** Notifikacija svim adminima (DB) + opcionalno vanjski kanali. */
    private function notifyAdmins(string $severity, string $title, string $body, bool $dispatch = false): void
    {
        foreach ($this->db->all("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'") as $a) {
            $this->db->run(
                "INSERT INTO notifications (user_id, severity, title, body) VALUES (?, ?, ?, ?)",
                [$a['id'], $severity, $title, $body]
            );
        }
        if ($dispatch) {
            System\Notifier::dispatch($this->db, $severity, $title, $body);
        }
    }

    /**
     * Dnevno: provjeri server IP na 30+ RBL lista. Nova listanja (kojih prije nije
     * bilo) → notifikacija adminima. Najveća rupa svih panela — svi plaćaju vanjski servis.
     */
    private function checkRbls(): void
    {
        $ip = $this->settingStr('server_ipv4');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return;
        }
        $prev = [];
        foreach ($this->db->all(
            'SELECT rbl, listed FROM rbl_checks r
             WHERE ip = ? AND checked_at = (SELECT MAX(checked_at) FROM rbl_checks WHERE ip = r.ip AND rbl = r.rbl)',
            [$ip]
        ) as $row) {
            $prev[(string) $row['rbl']] = (bool) $row['listed'];
        }
        $new_listings = [];
        foreach (System\Deliverability::checkRbls($ip) as $r) {
            $this->db->run('INSERT INTO rbl_checks (ip, rbl, listed) VALUES (?, ?, ?)', [$ip, $r['rbl'], (int) $r['listed']]);
            if ($r['listed'] && !($prev[$r['rbl']] ?? false)) {
                $new_listings[] = $r['rbl'];
            }
        }
        if ($new_listings === []) {
            return;
        }
        $this->notifyAdmins('error', "Server IP listan na RBL ($ip)", 'Nove liste: ' . implode(', ', $new_listings), true);
    }

    /** Svaki sat: ingest DMARC rua izvještaja iz panel mailboxa (ako je mail instaliran). */
    private function ingestDmarc(): void
    {
        if ($this->db->one("SELECT 1 FROM components WHERE name = 'postfix' AND status = 'installed'") === null) {
            return;
        }
        System\DmarcIngest::run($this->db);
    }

    /** Postavka iz settings tablice (JSON string) ili ''. */
    private function settingStr(string $key): string
    {
        $row = $this->db->one('SELECT value FROM settings WHERE `key` = ?', [$key]);
        if ($row === null) {
            return '';
        }
        $v = json_decode((string) $row['value'], true);
        return is_string($v) ? $v : '';
    }

    /** Kanonski oblik manifesta/payloada za ed25519 verifikaciju. @param array<string,mixed> $m */
    private static function canonicalSigned(array $m, array $keys): string
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = (string) ($m[$k] ?? '');
        }
        return (string) json_encode($out, JSON_UNESCAPED_SLASHES);
    }

    /** HTTPS GET/POST na master uz verificiran TLS. @return ?array<string,mixed> data ili null */
    private function masterCall(string $url, ?array $post = null): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post, JSON_UNESCAPED_SLASHES));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($code !== 200 || !is_array($data) || ($data['ok'] ?? false) !== true) {
            return null;
        }
        return $data['data'] ?? [];
    }

    /**
     * Svaka 4 h: provjeri ima li master noviju verziju panela. Verificira ed25519 potpis.
     * Auto politika → enqueue self_update; inače notifikacija adminima (jednom po verziji).
     */
    private function checkPanelUpdate(): void
    {
        $server = rtrim($this->settingStr('update_server'), '/');
        $pub = $this->settingStr('update_pubkey');
        $channel = $this->settingStr('update_channel') ?: 'stable';
        $current = $this->settingStr('panel_version') ?: '1.0.0';
        if ($server === '' || $pub === '') {
            return;
        }
        $d = $this->masterCall("$server/api/v1/distribution/manifest?channel=" . rawurlencode($channel));
        if ($d === null || !isset($d['manifest'], $d['signature'])) {
            return;
        }
        $manifest = $d['manifest'];
        $keys = ['version', 'channel', 'package_url', 'sha256', 'min_version', 'published_at'];
        if (!sodium_crypto_sign_verify_detached(base64_decode((string) $d['signature']), self::canonicalSigned($manifest, $keys), base64_decode($pub))) {
            return;
        }
        if (!version_compare((string) $manifest['version'], $current, '>')) {
            return;
        }
        $pending = $this->db->one("SELECT 1 FROM tasks WHERE op = 'panel.self_update' AND status IN ('pending', 'running')");
        if ($pending !== null) {
            return;
        }
        if ($this->settingStr('panel_update_auto') === 'auto') {
            $this->db->run(
                "INSERT INTO tasks (op, params) VALUES ('panel.self_update', ?)",
                [json_encode(['manifest' => $manifest, 'signature' => $d['signature'], 'pubkey' => $pub], JSON_UNESCAPED_SLASHES)]
            );
            return;
        }
        // notifikacija — jednom po verziji
        $notif_key = 'panel_update_notified_' . preg_replace('/[^0-9A-Za-z._-]/', '', (string) $manifest['version']);
        if ($this->settingStr($notif_key) !== '') {
            return;
        }
        foreach ($this->db->all("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'") as $a) {
            $this->db->run(
                "INSERT INTO notifications (user_id, severity, title, body) VALUES (?, 'info', ?, ?)",
                [$a['id'], "Panel update v{$manifest['version']} dostupan", 'Server → Distribucija → Primijeni update.']
            );
        }
        $this->db->run("INSERT INTO settings (`key`, value) VALUES (?, '\"1\"') ON DUPLICATE KEY UPDATE value = value", [$notif_key]);
    }

    /**
     * Svakih 6 h: re-validacija licence na masteru (hvata suspend/revoke/istek).
     * Verificira potpis tokena; sprema status u settings. Offline = zadrži zadnji token.
     */
    private function checkLicense(): void
    {
        $server = rtrim($this->settingStr('update_server'), '/');
        $pub = $this->settingStr('update_pubkey');
        $key = $this->settingStr('license_key');
        if ($server === '' || $pub === '' || $key === '') {
            return;
        }
        $d = $this->masterCall("$server/api/v1/license/check", [
            'key' => $key, 'fingerprint' => $this->settingStr('license_fingerprint'),
        ]);
        if ($d === null || !isset($d['license'], $d['signature'])) {
            return; // offline → zadrži postojeći status
        }
        $payload = $d['license'];
        $keys = ['key', 'tier', 'status', 'expires_at', 'fingerprint', 'issued_at'];
        if (!sodium_crypto_sign_verify_detached(base64_decode((string) $d['signature']), self::canonicalSigned($payload, $keys), base64_decode($pub))) {
            return;
        }
        $this->db->run("INSERT INTO settings (`key`, value) VALUES ('license_status', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [json_encode((string) $payload['status'])]);
        $this->db->run("INSERT INTO settings (`key`, value) VALUES ('license_token', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [json_encode(json_encode($d, JSON_UNESCAPED_SLASHES))]);
    }

    /** Svakih 30 min: osvježi disk (du) i tip aplikacije po aktivnom vhostu (Siteovi lista). */
    private function refreshVhostStats(): void
    {
        $vhosts = $this->db->all("SELECT id, domain, docroot FROM vhosts WHERE status = 'active'");
        foreach ($vhosts as $v) {
            try {
                [$disk, $app] = System\VhostStats::collect(Validator::VHOST_ROOT . '/' . $v['domain'], (string) $v['docroot']);
                [$traffic7, $spark] = System\VhostStats::traffic((string) $v['domain']);
                $this->db->run(
                    'UPDATE vhosts SET disk_bytes = ?, app_type = ?, traffic_7d = ?, traffic_spark = ?, stats_at = NOW() WHERE id = ?',
                    [$disk, $app, $traffic7, json_encode($spark), (int) $v['id']]
                );
            } catch (\Throwable $e) {
                error_log('forge-agentd vhost_stats [' . $v['domain'] . ']: ' . $e->getMessage());
            }
        }
    }

    /** Svaka 4 h: scan dostupnih updatea (kao task — vidljivo u UI-ju). */
    private function enqueueUpdatesScan(): void
    {
        $pending = $this->db->one(
            "SELECT 1 FROM tasks WHERE op = 'updates.scan' AND status IN ('pending', 'running')"
        );
        if ($pending === null) {
            $this->db->run("INSERT INTO tasks (op, params) VALUES ('updates.scan', '{}')");
        }
    }

    /**
     * Auto-update: komponente s politikom auto_all / auto_security_only,
     * unutar maintenance windowa, bez major skokova (ti su UVIJEK ručni).
     */
    private function enqueueAutoUpdates(): void
    {
        $due = $this->db->all(
            "SELECT c.name, c.current_version, c.available_version, c.security_update,
                    p.mode, p.window_start, p.window_end, p.window_days
             FROM components c JOIN update_policies p ON p.component_id = c.id
             WHERE c.available_version IS NOT NULL AND c.status = 'installed'
               AND p.mode IN ('auto_all', 'auto_security_only')"
        );
        foreach ($due as $component) {
            if ($component['mode'] === 'auto_security_only' && !(bool) $component['security_update']) {
                continue;
            }
            $days = json_decode((string) $component['window_days'], true) ?: [7];
            if (!System\UpdatePolicy::inWindow((string) $component['window_start'], (string) $component['window_end'], $days)) {
                continue;
            }
            if (System\UpdatePolicy::isMajorJump((string) $component['current_version'], (string) $component['available_version'])) {
                continue; // major skok = ručni wizard, nikad auto
            }
            $pending = $this->db->one(
                "SELECT 1 FROM tasks WHERE op = 'updates.apply' AND status IN ('pending', 'running')
                 AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.component')) = ?",
                [$component['name']]
            );
            if ($pending === null) {
                $this->db->run(
                    "INSERT INTO tasks (op, params) VALUES ('updates.apply', ?)",
                    [json_encode(['component' => $component['name']])]
                );
            }
        }
    }

    /**
     * Suite watcher: repoi na noble fallbacku automatski migriraju na resolute
     * kad postane dostupan + notifikacija adminima.
     */
    private function watchSuites(): void
    {
        $repo_urls = [
            'ondrej-php' => 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu',
            'ondrej-apache2' => 'https://ppa.launchpadcontent.net/ondrej/apache2/ubuntu',
            'nginx' => 'https://nginx.org/packages/mainline/ubuntu',
            'mariadb' => 'https://deb.mariadb.org/11.8/ubuntu',
        ];
        $fallbacks = $this->db->all("SELECT id, name FROM components WHERE repo_suite = 'noble'");
        foreach ($fallbacks as $repo) {
            $url = $repo_urls[$repo['name']] ?? null;
            $sources_file = "/etc/apt/sources.list.d/forgepanel-{$repo['name']}.sources";
            if ($url === null || !is_file($sources_file)) {
                continue;
            }
            $ch = curl_init("$url/dists/resolute/Release");
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                // Verificiran TLS: MITM ne smije lažno javiti da resolute suite postoji i okinuti switch
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code !== 200) {
                continue;
            }
            $content = (string) file_get_contents($sources_file);
            file_put_contents($sources_file, str_replace('Suites: noble', 'Suites: resolute', $content));
            $this->db->run("UPDATE components SET repo_suite = 'resolute' WHERE id = ?", [$repo['id']]);
            $admins = $this->db->all("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'");
            foreach ($admins as $admin) {
                $this->db->run(
                    "INSERT INTO notifications (user_id, severity, title, body) VALUES (?, 'info', ?, ?)",
                    [$admin['id'], "Repo {$repo['name']} migriran na resolute suite",
                        'Upstream je objavio resolute pakete; fallback na noble više nije potreban.']
                );
            }
        }
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
                $title = ($status === 'down' ? 'Stranica nedostupna: ' : 'Stranica ponovno dostupna: ') . $probe['target'];
                if ($owner !== null) {
                    $this->db->run(
                        'INSERT INTO notifications (user_id, severity, title, body) VALUES (?, ?, ?, ?)',
                        [$owner['user_id'], $status === 'down' ? 'error' : 'info', $title, $detail]
                    );
                }
                // Vanjski kanali (e-mail/Telegram/webhook) — promjene dostupnosti su uvijek bitne
                System\Notifier::dispatch($this->db, $status === 'down' ? 'error' : 'info', $title, (string) $detail);
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
        $now = microtime(true);
        $meminfo = [];
        foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m)) {
                $meminfo[$m[1]] = (int) $m[2] * 1024;
            }
        }
        $load = sys_getloadavg() ?: [0.0];
        $cpu_count = max(1, preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')));

        // [scope, metric, value]
        $rows = [
            ['server', 'cpu_load1', $load[0]],
            ['server', 'mem_used_bytes', (float) (($meminfo['MemTotal'] ?? 0) - ($meminfo['MemAvailable'] ?? 0))],
            ['server', 'disk_used_bytes', (float) disk_total_space('/') - (float) disk_free_space('/')],
        ];

        // Ukupni CPU % iz /proc/stat (delta busy/total između tickova)
        if (preg_match('/^cpu\s+(.+)$/m', (string) @file_get_contents('/proc/stat'), $m)) {
            $f = array_map('floatval', preg_split('/\s+/', trim($m[1])));
            $total = array_sum($f);
            $idle = ($f[3] ?? 0) + ($f[4] ?? 0); // idle + iowait
            if (isset($this->rate_last['cpu_total'])) {
                [$pt, $pidle] = [$this->rate_last['cpu_total'][1], $this->rate_last['cpu_idle'][1]];
                $dt = $total - $pt;
                $rows[] = ['server', 'cpu_pct', $dt > 0 ? max(0.0, min(100.0, (1 - ($idle - $pidle) / $dt) * 100)) : 0.0];
            }
            $this->rate_last['cpu_total'] = [$now, $total];
            $this->rate_last['cpu_idle'] = [$now, $idle];
        }

        // Disk I/O (B/s) iz /proc/diskstats — samo fizički diskovi (sd*, nvme*, vd*, xvd*)
        $r_sectors = $w_sectors = 0.0;
        foreach (explode("\n", (string) @file_get_contents('/proc/diskstats')) as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 10 && preg_match('/^(sd[a-z]+|nvme\d+n\d+|vd[a-z]+|xvd[a-z]+)$/', $p[2])) {
                $r_sectors += (float) $p[5];
                $w_sectors += (float) $p[9];
            }
        }
        foreach ([['disk_read_bps', $r_sectors * 512], ['disk_write_bps', $w_sectors * 512]] as [$metric, $cumulative]) {
            if (($rate = $this->rate($metric, $now, $cumulative)) !== null) {
                $rows[] = ['server', $metric, $rate];
            }
        }

        // Mreža (B/s) iz /proc/net/dev — sve osim loopbacka
        $rx = $tx = 0.0;
        foreach (explode("\n", (string) @file_get_contents('/proc/net/dev')) as $line) {
            if (preg_match('/^\s*([^:]+):\s*(\d+)(?:\s+\d+){7}\s+(\d+)/', $line, $m) && trim($m[1]) !== 'lo') {
                $rx += (float) $m[2];
                $tx += (float) $m[3];
            }
        }
        foreach ([['net_rx_bps', $rx], ['net_tx_bps', $tx]] as [$metric, $cumulative]) {
            if (($rate = $this->rate($metric, $now, $cumulative)) !== null) {
                $rows[] = ['server', $metric, $rate];
            }
        }

        // Per-servis CPU % (od ukupnog hosta) + RAM, iz cgroup v2 (system.slice)
        foreach ($this->trackedServices() as $unit) {
            $cg = "/sys/fs/cgroup/system.slice/{$unit}.service";
            if (!is_dir($cg)) {
                continue;
            }
            $scope = 'service:' . preg_replace('/\.service$/', '', $unit);
            $mem = (int) trim((string) @file_get_contents("$cg/memory.current"));
            if ($mem > 0) {
                $rows[] = [$scope, 'mem_bytes', (float) $mem];
            }
            if (preg_match('/usage_usec\s+(\d+)/', (string) @file_get_contents("$cg/cpu.stat"), $cm)) {
                $key = "cpu:$scope";
                if (isset($this->rate_last[$key])) {
                    $dt = $now - $this->rate_last[$key][0];
                    $dusec = (float) $cm[1] - $this->rate_last[$key][1];
                    if ($dt > 0 && $dusec >= 0) {
                        $rows[] = [$scope, 'cpu_pct', min(100.0, ($dusec / 1e6) / $dt / $cpu_count * 100)];
                    }
                }
                $this->rate_last[$key] = [$now, (float) $cm[1]];
            }
        }

        // Batch insert
        $values = [];
        $params = [];
        foreach ($rows as [$scope, $metric, $value]) {
            $values[] = '(?, ?, ?, NOW())';
            array_push($params, $scope, $metric, $value);
        }
        if ($values !== []) {
            $this->db->run(
                "INSERT INTO monitoring_metrics (scope, metric, value, ts) VALUES " . implode(', ', $values),
                $params
            );
        }
    }

    /** Stopa (jedinica/s) iz kumulativnog brojača; null pri prvom uzorku ili reset/overflow. */
    private function rate(string $metric, float $now, float $cumulative): ?float
    {
        $prev = $this->rate_last[$metric] ?? null;
        $this->rate_last[$metric] = [$now, $cumulative];
        if ($prev === null) {
            return null;
        }
        $dt = $now - $prev[0];
        $delta = $cumulative - $prev[1];
        return ($dt > 0 && $delta >= 0) ? $delta / $dt : null;
    }

    /** Servisi koje pratimo po cgroupu — fiksni set + sve prisutne php*-fpm verzije. */
    private function trackedServices(): array
    {
        $base = [
            'nginx', 'apache2', 'mariadb', 'mysql', 'postfix', 'dovecot', 'rspamd',
            'named', 'proftpd', 'fail2ban', 'redis-server', 'docker', 'forge-agentd',
        ];
        foreach (glob('/sys/fs/cgroup/system.slice/php*-fpm.service') ?: [] as $path) {
            $base[] = basename($path, '.service');
        }
        return $base;
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

    /**
     * Panel hostname (:8443) mora dobiti pravi cert čim DNS/port 80 prorade —
     * installer ostavlja self-signed, a ovo se vrti svakih 15 min dok ne uspije.
     * Self-healing: pad (DNS još ne resolva, port 80 zatvoren) se ponavlja s
     * 1 h backoffa, bez ručne intervencije.
     */
    private function ensurePanelCert(): void
    {
        $fqdn = (string) $this->config->get('panel_fqdn', '');
        if ($fqdn === '') {
            return;
        }
        $cert_file = '/etc/forgepanel/ssl/panel/fullchain.pem';
        $needs = true;
        if (is_file($cert_file)) {
            $parsed = openssl_x509_parse((string) file_get_contents($cert_file));
            if (is_array($parsed)) {
                $self_signed = ($parsed['issuer'] ?? []) === ($parsed['subject'] ?? []);
                $expiring = (int) ($parsed['validTo_time_t'] ?? 0) < time() + 30 * 86400;
                $needs = $self_signed || $expiring;
            }
        }
        if (!$needs) {
            return;
        }
        $blocked = $this->db->one(
            "SELECT 1 FROM tasks
             WHERE op = 'ssl.panel_issue'
               AND (status IN ('pending', 'running')
                    OR (status = 'failed' AND finished_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)))
             LIMIT 1"
        );
        if ($blocked !== null) {
            return;
        }
        $this->db->run("INSERT INTO tasks (op, params) VALUES ('ssl.panel_issue', '{}')");
    }

    private function enqueueSslRenewals(): void
    {
        $contact = $this->config->get('acme_email', 'admin@localhost.localdomain');
        $panel_fqdn = (string) $this->config->get('panel_fqdn', '');
        $due = $this->db->all(
            "SELECT c.id, c.hostname, c.vhost_id
             FROM ssl_certs c
             WHERE c.auto_renew = 1 AND c.status = 'active'
               AND c.expires_at < DATE_ADD(NOW(), INTERVAL 30 DAY)"
        );
        foreach ($due as $cert) {
            // panel cert obnavlja ensurePanelCert (drugi path + bez 'www.' prefiksa)
            if ($cert['vhost_id'] === null && $cert['hostname'] === $panel_fqdn) {
                continue;
            }
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
