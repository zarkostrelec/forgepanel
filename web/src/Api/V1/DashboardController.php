<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Dashboard agregati: live feed događaja, zadnji deployi i Forge AI preporuke.
 * Preporuke su RULE-BASED provjere stvarnog stanja (SSL istek, disk, updatei,
 * pali taskovi…) — bez izmišljenih brojki; "Otvori analizu" šalje kontekst
 * lokalnom Claude asistentu za dublju dijagnozu.
 */
final class DashboardController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/dashboard/feed', $this->feed(...));
        $router->add('GET', '/api/v1/dashboard/insights', $this->insights(...));
        $router->add('GET', '/api/v1/dashboard/usage', $this->usage(...));
    }

    /**
     * Potrošnja plana pozivatelja (disk, domene, mailboxi, baze) vs. limiti —
     * klijentski dashboard. Zbroj preko svih pretplata korisnika.
     */
    private function usage(Request $request): never
    {
        $ctx = $this->ctx($request, 'monitoring:read');
        $sids = $ctx->subscription_ids;
        if ($sids === [] && $ctx->isAdmin()) {
            $sids = [$this->adminSubscription($ctx)];
        }
        if ($sids === []) {
            Response::ok(['disk' => null, 'domains' => null, 'mailboxes' => null, 'databases' => null]);
        }
        $in = implode(',', array_fill(0, count($sids), '?'));

        $limits = $this->app->db->one(
            "SELECT COALESCE(SUM(p.disk_bytes),0) AS disk, COALESCE(SUM(p.max_domains),0) AS domains,
                    COALESCE(SUM(p.max_mailboxes),0) AS mailboxes, COALESCE(SUM(p.max_databases),0) AS databases
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.id IN ($in)",
            $sids
        ) ?? ['disk' => 0, 'domains' => 0, 'mailboxes' => 0, 'databases' => 0];

        $disk_used = (int) ($this->app->db->one("SELECT COALESCE(SUM(disk_bytes),0) AS u FROM vhosts WHERE subscription_id IN ($in)", $sids)['u'] ?? 0);
        $domains_used = (int) ($this->app->db->one("SELECT COUNT(*) AS u FROM vhosts WHERE subscription_id IN ($in)", $sids)['u'] ?? 0);
        $mb_used = (int) ($this->app->db->one("SELECT COUNT(*) AS u FROM mailboxes m JOIN mail_domains d ON d.id = m.mail_domain_id WHERE d.subscription_id IN ($in)", $sids)['u'] ?? 0);
        $db_used = (int) ($this->app->db->one("SELECT COUNT(*) AS u FROM db_databases WHERE subscription_id IN ($in)", $sids)['u'] ?? 0);

        Response::ok([
            'disk' => ['used' => $disk_used, 'limit' => (int) $limits['disk']],
            'domains' => ['used' => $domains_used, 'limit' => (int) $limits['domains']],
            'mailboxes' => ['used' => $mb_used, 'limit' => (int) $limits['mailboxes']],
            'databases' => ['used' => $db_used, 'limit' => (int) $limits['databases']],
        ]);
    }

    /** Spojeni kronološki feed: audit (kurirani opovi) + taskovi + uptime promjene. */
    private function feed(Request $request): never
    {
        $this->ctx($request, 'monitoring:read')->requireRole('admin');
        $events = [];

        // Završeni/pali taskovi (zadnja 24 h)
        foreach ($this->app->db->all(
            "SELECT op, status, error, finished_at AS ts FROM tasks
             WHERE finished_at IS NOT NULL AND finished_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND op NOT LIKE 'assistant%'
             ORDER BY finished_at DESC LIMIT 14"
        ) as $row) {
            $ok = $row['status'] === 'done';
            $events[] = [
                'ts' => $row['ts'],
                'kind' => 'task',
                'severity' => $ok ? 'ok' : 'err',
                'text' => $row['op'] . ' — ' . ($ok ? 'uspješno' : 'neuspješno' . ($row['error'] ? ': ' . mb_substr((string) $row['error'], 0, 90) : '')),
            ];
        }

        // Kurirani audit događaji (login, ssl, vhost, firewall, branding…)
        foreach ($this->app->db->all(
            "SELECT action, detail, created_at AS ts FROM audit_log
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND action IN ('auth.login','auth.login_failed','vhost.create','vhost.delete',
                              'ssl.issued','firewall.ban','assistant.exec','branding.update',
                              'cloudflare.sync','cloudflare.purge','user.create')
             ORDER BY created_at DESC LIMIT 14"
        ) as $row) {
            $detail = '';
            $parsed = json_decode((string) ($row['detail'] ?? ''), true);
            if (is_array($parsed)) {
                $detail = implode(' ', array_map(
                    static fn ($v) => is_scalar($v) ? (string) $v : '',
                    array_slice($parsed, 0, 2)
                ));
            }
            $events[] = [
                'ts' => $row['ts'],
                'kind' => 'audit',
                'severity' => str_contains((string) $row['action'], 'failed') ? 'warn' : 'info',
                'text' => trim($row['action'] . ($detail !== '' ? ' · ' . mb_substr($detail, 0, 80) : '')),
            ];
        }

        // Uptime promjene stanja
        foreach ($this->app->db->all(
            "SELECT e.event, e.created_at AS ts, p.target FROM uptime_events e
             JOIN uptime_probes p ON p.id = e.probe_id
             WHERE e.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY e.created_at DESC LIMIT 8"
        ) as $row) {
            $up = $row['event'] === 'up';
            $events[] = [
                'ts' => $row['ts'],
                'kind' => 'uptime',
                'severity' => $up ? 'ok' : 'err',
                'text' => $row['target'] . ' — ' . ($up ? 'ponovno dostupno' : 'NEDOSTUPNO'),
            ];
        }

        usort($events, static fn ($a, $b) => strcmp((string) $b['ts'], (string) $a['ts']));

        // Zadnji deployi (git modul)
        $deploys = $this->app->db->all(
            "SELECT g.last_deploy_at, g.branch, g.last_commit, v.domain
             FROM git_repos g JOIN vhosts v ON v.id = g.vhost_id
             WHERE g.last_deploy_at IS NOT NULL
             ORDER BY g.last_deploy_at DESC LIMIT 5"
        );

        Response::ok(['events' => array_slice($events, 0, 18), 'deploys' => $deploys]);
    }

    /** Rule-based preporuke iz stvarnog stanja; svaka nosi ai_prompt za dublju analizu. */
    private function insights(Request $request): never
    {
        $this->ctx($request, 'monitoring:read')->requireRole('admin');
        $items = [];

        // SSL certifikati pri isteku
        foreach ($this->app->db->all(
            "SELECT hostname, DATEDIFF(expires_at, NOW()) AS days FROM ssl_certs
             WHERE status = 'active' AND expires_at < DATE_ADD(NOW(), INTERVAL 14 DAY)
             ORDER BY expires_at LIMIT 5"
        ) as $c) {
            $items[] = [
                'severity' => (int) $c['days'] < 5 ? 'err' : 'warn',
                'text' => "SSL za {$c['hostname']} istječe za {$c['days']} dana",
                'ai_prompt' => "SSL certifikat za {$c['hostname']} istječe za {$c['days']} dana, a auto-renew ga očito nije obnovio. Provjeri zašto (DNS, port 80, ACME log) i predloži korake.",
            ];
        }

        // Pali taskovi (24 h)
        $failed = $this->app->db->one(
            "SELECT COUNT(*) AS n, MAX(op) AS last_op FROM tasks
             WHERE status = 'failed' AND finished_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND op NOT LIKE 'assistant%'"
        );
        if ($failed !== null && (int) $failed['n'] > 0) {
            $items[] = [
                'severity' => 'warn',
                'text' => "{$failed['n']} neuspjelih taskova u zadnja 24 h (zadnji: {$failed['last_op']})",
                'ai_prompt' => "U zadnja 24 h palo je {$failed['n']} taskova (npr. {$failed['last_op']}). Pogledaj tablicu tasks u forgepanel bazi (status='failed', kolona error) i objasni uzroke + korake.",
            ];
        }

        // Disk pun — stat panelovog direktorija (unutar open_basedira, isti root FS);
        // '/' bi pao na open_basedir restrikciju web sloja
        $disk_total = (float) @disk_total_space(__DIR__);
        $disk_free = (float) @disk_free_space(__DIR__);
        if ($disk_total > 0 && $disk_free / $disk_total < 0.15) {
            $pct = round((1 - $disk_free / $disk_total) * 100);
            $items[] = [
                'severity' => $pct >= 92 ? 'err' : 'warn',
                'text' => "Disk je {$pct}% pun",
                'ai_prompt' => "Disk na serveru je {$pct}% pun. Pronađi što zauzima najviše prostora (logovi, backupi, /var) i predloži sigurno čišćenje.",
            ];
        }

        // Dostupni updatei komponenti
        $updates = $this->app->db->one(
            "SELECT COUNT(*) AS n FROM components
             WHERE available_version IS NOT NULL AND available_version <> ''
               AND available_version <> current_version"
        );
        if ($updates !== null && (int) $updates['n'] > 0) {
            $items[] = [
                'severity' => 'info',
                'text' => "{$updates['n']} komponenti ima dostupan update",
                'ai_prompt' => null,
            ];
        }

        // RAM pritisak provjerava frontend iz /monitoring/now (ima total+available)

        Response::ok(['items' => array_slice($items, 0, 6)]);
    }
}
