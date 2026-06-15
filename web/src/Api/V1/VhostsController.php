<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class VhostsController extends Controller
{
    private const PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4', '8.5'];

    /** Per-domena podesivi PHP ini-ovi (Plesk-style) + dozvoljeni format. */
    private const PHP_SETTING_RULES = [
        'memory_limit'        => '/^(-1|\d{1,6}[KMGkmg]?)$/',
        'max_execution_time'  => '/^\d{1,6}$/',
        'max_input_time'      => '/^-?\d{1,6}$/',
        'post_max_size'       => '/^\d{1,6}[KMGkmg]?$/',
        'upload_max_filesize' => '/^\d{1,6}[KMGkmg]?$/',
        'max_input_vars'      => '/^\d{1,6}$/',
        'opcache.enable'      => '/^[01]$/',
        'display_errors'      => '/^(On|Off|on|off|0|1)$/',
        'disable_functions'   => '/^[a-zA-Z0-9_,]{0,500}$/',
    ];

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/vhosts', $this->index(...));
        $router->add('POST', '/api/v1/vhosts', $this->create(...));
        $router->add('GET', '/api/v1/vhosts/{id}', $this->show(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}', $this->delete(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/php', $this->setPhp(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/php-settings', $this->setPhpSettings(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/backend', $this->setBackend(...));
    }

    /** Uređivanje PHP ini postavki po domeni (kao Plesk) → FPM pool + reload. */
    private function setPhpSettings(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $in = is_array($request->body['settings'] ?? null) ? $request->body['settings'] : [];
        $clean = [];
        foreach (self::PHP_SETTING_RULES as $key => $rule) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $value = trim((string) $in[$key]);
            if ($value === '') {
                continue; // prazno = PHP default (ne forsiramo override)
            }
            if (!preg_match($rule, $value)) {
                throw new HttpException(422, 'invalid_php_setting');
            }
            $clean[$key] = $value;
        }

        $this->app->db->run('UPDATE vhosts SET php_settings = ? WHERE id = ?', [json_encode($clean), $vhost['id']]);
        // Async task: reload php-fpm servisa (isti servis poslužuje i panel) NE smije
        // ići sinkrono unutar requesta — prekinuo bi sam taj request (bad_response).
        $task_id = $this->app->tasks->enqueue('vhost.php_settings', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'php_version' => $vhost['php_version'],
            'settings' => $clean,
        ], $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'vhost.php_settings', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id, 'settings' => $clean], 202);
    }

    /** Per-domena izbor: nginx (default, brže) ili nginx → Apache (.htaccess/WordPress). */
    private function setBackend(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $backend = $request->str('web_backend') ?? '';
        if (!in_array($backend, ['nginx', 'nginx_apache'], true)) {
            throw new HttpException(422, 'invalid_backend');
        }

        $task_id = $this->app->tasks->enqueue('vhost.backend_set', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'php_version' => $vhost['php_version'],
            'backend' => $backend,
        ], $ctx->user_id);

        $this->app->audit->log($ctx->user_id, $ctx->email, 'vhost.backend_set', ['domain' => $vhost['domain'], 'backend' => $backend], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:read');
        if ($ctx->isAdmin()) {
            // + SSL istek (najraniji aktivni cert) i git deploy info za Siteovi listu
            Response::ok($this->app->db->all(
                "SELECT v.*,
                        (SELECT DATEDIFF(MIN(c.expires_at), NOW()) FROM ssl_certs c
                          WHERE c.vhost_id = v.id AND c.status = 'active') AS ssl_days,
                        (SELECT g.branch FROM git_repos g WHERE g.vhost_id = v.id LIMIT 1) AS git_branch,
                        (SELECT g.last_deploy_at FROM git_repos g WHERE g.vhost_id = v.id LIMIT 1) AS git_last_deploy,
                        (SELECT COUNT(*) FROM vhost_aliases a WHERE a.vhost_id = v.id) AS alias_count
                 FROM vhosts v ORDER BY v.domain"
            ));
        }
        // Vlastiti (po subscription) + delegirani vhostovi
        $delegated = $ctx->delegatedVhostIds();
        $conditions = [];
        $args = [];
        if ($ctx->subscription_ids !== []) {
            $conditions[] = 'subscription_id IN (' . implode(',', array_fill(0, count($ctx->subscription_ids), '?')) . ')';
            $args = $ctx->subscription_ids;
        }
        if ($delegated !== []) {
            $conditions[] = 'id IN (' . implode(',', array_fill(0, count($delegated), '?')) . ')';
            $args = [...$args, ...$delegated];
        }
        if ($conditions === []) {
            Response::ok([]);
        }
        Response::ok($this->app->db->all(
            'SELECT *, (SELECT COUNT(*) FROM vhost_aliases a WHERE a.vhost_id = vhosts.id) AS alias_count
             FROM vhosts WHERE ' . implode(' OR ', $conditions) . ' ORDER BY domain',
            $args
        ));
    }

    private function show(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $vhost['uptime'] = $this->app->db->one(
            'SELECT type, target, last_status, response_ms, interval_s FROM uptime_probes WHERE vhost_id = ? LIMIT 1',
            [$vhost['id']]
        );
        Response::ok($vhost);
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');

        $domain = strtolower(trim($request->str('domain') ?? ''));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new HttpException(422, 'invalid_domain');
        }
        $php_version = $request->str('php_version') ?? $this->setting('default_php', '8.5');
        if (!in_array($php_version, self::PHP_VERSIONS, true)) {
            throw new HttpException(422, 'invalid_php_version');
        }

        $subscription_id = $request->int('subscription_id')
            ?? ($ctx->subscription_ids[0] ?? ($ctx->isAdmin() ? $this->adminSubscription($ctx) : null));
        if ($subscription_id === null) {
            throw new HttpException(422, 'subscription_required');
        }
        $ctx->requireSubscription($subscription_id);

        $sub = $this->app->db->one(
            'SELECT s.id, p.max_domains, p.php_versions, p.cpu_quota_pct, p.memory_max_bytes, p.tasks_max
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.id = ? AND s.status = \'active\'',
            [$subscription_id]
        ) ?? throw new HttpException(422, 'subscription_inactive');

        $allowed_php = json_decode((string) $sub['php_versions'], true) ?: [];
        if (!$ctx->isAdmin() && !in_array($php_version, $allowed_php, true)) {
            throw new HttpException(422, 'php_version_not_in_plan');
        }

        $count = $this->app->db->one('SELECT COUNT(*) AS n FROM vhosts WHERE subscription_id = ?', [$subscription_id]);
        if (!$ctx->isAdmin() && (int) $count['n'] >= (int) $sub['max_domains']) {
            throw new HttpException(422, 'plan_domain_limit_reached');
        }

        if ($this->app->db->one('SELECT 1 FROM vhosts WHERE domain = ?', [$domain]) !== null) {
            throw new HttpException(409, 'domain_exists');
        }

        $this->app->db->run(
            'INSERT INTO vhosts (domain, subscription_id, sys_user, php_version, docroot, status)
             VALUES (?, ?, ?, ?, ?, \'creating\')',
            [$domain, $subscription_id, 'pending', $php_version, "/var/www/vhosts/$domain/httpdocs"]
        );
        $vhost_id = $this->app->db->lastId();
        $this->app->db->run('UPDATE vhosts SET sys_user = ? WHERE id = ?', ['vh_' . $vhost_id, $vhost_id]);

        $task_id = $this->app->tasks->enqueue('vhost.create', [
            'vhost_id' => $vhost_id,
            'domain' => $domain,
            'php_version' => $php_version,
            'cpu_quota_pct' => (int) $sub['cpu_quota_pct'],
            'memory_max_bytes' => (int) $sub['memory_max_bytes'],
            'tasks_max' => (int) $sub['tasks_max'],
        ], $ctx->user_id);

        // AutoSSL: svaki novi vhost automatski dobiva certifikat
        $contact = $this->setting('acme_email', $ctx->email);
        $ssl_task_id = $this->app->tasks->enqueue('ssl.issue', [
            'hostnames' => [$domain, "www.$domain"],
            'contact_email' => $contact,
            'vhost_id' => $vhost_id,
        ], $ctx->user_id);
        $this->app->db->run(
            'INSERT INTO ssl_certs (vhost_id, hostname, type, cert_path, key_path, expires_at, status)
             VALUES (?, ?, \'letsencrypt\', ?, ?, NOW(), \'pending\')',
            [$vhost_id, $domain, "/etc/forgepanel/ssl/$domain/fullchain.pem", "/etc/forgepanel/ssl/$domain/privkey.pem"]
        );

        // Eksterni uptime monitoring — svaki vhost automatski dobiva HTTPS probu
        $this->app->db->run(
            "INSERT INTO uptime_probes (vhost_id, type, target, interval_s) VALUES (?, 'https', ?, 300)",
            [$vhost_id, $domain]
        );

        $this->app->audit->log($ctx->user_id, $ctx->email, 'vhost.create', ['domain' => $domain], $request->ip);
        Response::ok(['vhost_id' => $vhost_id, 'task_id' => $task_id, 'ssl_task_id' => $ssl_task_id], 202);
    }

    private function delete(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $task_id = $this->app->tasks->enqueue('vhost.delete', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'php_version' => $vhost['php_version'],
        ], $ctx->user_id);

        $this->app->db->run('DELETE FROM vhosts WHERE id = ?', [$vhost['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'vhost.delete', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    private function setPhp(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $new_version = $request->str('php_version') ?? throw new HttpException(400, 'php_version_required');
        if (!in_array($new_version, self::PHP_VERSIONS, true)) {
            throw new HttpException(422, 'invalid_php_version');
        }
        // Ista granica plana kao kod kreiranja — promjena verzije ne smije zaobići plan
        if (!$ctx->isAdmin()) {
            $plan = $this->app->db->one(
                'SELECT p.php_versions FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?',
                [$vhost['subscription_id']]
            );
            $allowed_php = json_decode((string) ($plan['php_versions'] ?? '[]'), true) ?: [];
            if (!in_array($new_version, $allowed_php, true)) {
                throw new HttpException(422, 'php_version_not_in_plan');
            }
        }

        $this->app->agent->call('vhost.php_set', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'old_version' => $vhost['php_version'],
            'new_version' => $new_version,
        ], timeout_s: 60);

        $this->app->db->run('UPDATE vhosts SET php_version = ? WHERE id = ?', [$new_version, $vhost['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'vhost.php_set', ['domain' => $vhost['domain'], 'php' => $new_version], $request->ip);
        Response::ok(['php_version' => $new_version]);
    }
}
