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
        $router->add('POST', '/api/v1/vhosts/{id}/subdomains', $this->createSubdomain(...));
        $router->add('GET', '/api/v1/vhosts/{id}', $this->show(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}', $this->delete(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/php', $this->setPhp(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/php-settings', $this->setPhpSettings(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/backend', $this->setBackend(...));
        $router->add('POST', '/api/v1/vhosts/{id}/dns/repair', $this->repairDns(...));
    }

    /**
     * Popravak/dovršetak DNS-a za postojeću domenu: kreira lokalnu zonu ako fali i
     * (opcionalno) gurne zapise na odabrani Cloudflare račun — ista logika kao pri
     * kreiranju. Idempotentno: ponovni poziv ne duplicira zapise.
     */
    private function repairDns(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        // Poddomena: NE stvaraj zasebnu zonu — zapisi idu u zonu roditelja (+ CF veza za
        // DNS-01), pa re-izdaj certifikat (sad kad veza postoji AutoSSL ide DNS-01).
        if ($vhost['parent_vhost_id'] !== null) {
            $parent = $this->app->db->one('SELECT id, domain FROM vhosts WHERE id = ?', [(int) $vhost['parent_vhost_id']]);
            $dns = ['local' => false, 'cloudflare' => false];
            if ($parent !== null && str_ends_with((string) $vhost['domain'], '.' . $parent['domain'])) {
                $label = substr((string) $vhost['domain'], 0, -strlen('.' . $parent['domain']));
                try {
                    $dns = \ForgePanel\Web\Core\DomainProvision::subdomain($this->app, (int) $parent['id'], (string) $parent['domain'], $label, (int) $vhost['id']);
                } catch (\Throwable $e) {
                    error_log('forgepanel: subdomain dns repair: ' . $e->getMessage());
                }
            }
            $ssl_task_id = $this->app->tasks->enqueue('ssl.issue', [
                'hostnames' => [(string) $vhost['domain']],
                'contact_email' => $this->setting('acme_email', $ctx->email),
                'vhost_id' => (int) $vhost['id'],
            ], $ctx->user_id);
            $this->app->audit->log($ctx->user_id, $ctx->email, 'vhost.dns_repair', ['domain' => $vhost['domain'], 'subdomain' => true], $request->ip);
            Response::ok(['dns' => $dns, 'ssl_task_id' => $ssl_task_id]);
        }

        $dns = $this->provisionDns($ctx, $request, (int) $vhost['id'], (string) $vhost['domain'], (int) $vhost['subscription_id']);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'vhost.dns_repair', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['dns' => $dns]);
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
        if (!in_array($backend, ['nginx', 'nginx_apache', 'php_legacy'], true)) {
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
        $this->requireActiveLicense();

        $domain = strtolower(trim($request->str('domain') ?? ''));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new HttpException(422, 'invalid_domain');
        }
        $php_version = $request->str('php_version') ?? $this->setting('default_php', '8.5');
        if (!in_array($php_version, self::PHP_VERSIONS, true)) {
            throw new HttpException(422, 'invalid_php_version');
        }

        // Redirect vhost: nije zaseban "paket" (FPM/docroot) — samo 301/302 na postojeću
        // stranicu na ovom hostingu (čuva path+query). Cilj mora već postojati kao vhost.
        $web_backend = $request->str('web_backend') === 'redirect' ? 'redirect' : 'nginx';
        $redirect_target = null;
        $redirect_code = 301;
        if ($web_backend === 'redirect') {
            $redirect_target = strtolower(trim($request->str('redirect_target') ?? ''));
            if ($redirect_target === '' || $redirect_target === $domain) {
                throw new HttpException(422, 'redirect_target_required');
            }
            if ($this->app->db->one('SELECT 1 FROM vhosts WHERE domain = ?', [$redirect_target]) === null) {
                throw new HttpException(422, 'redirect_target_not_found');
            }
            $redirect_code = $request->int('redirect_code') === 302 ? 302 : 301;
        }

        $subscription_id = $request->int('subscription_id')
            ?? ($ctx->subscription_ids[0] ?? ($ctx->isAdmin() ? $this->adminSubscription($ctx) : null));
        if ($subscription_id === null) {
            throw new HttpException(422, 'subscription_required');
        }
        $ctx->requireSubscription($subscription_id);

        $sub = $this->app->db->one(
            'SELECT s.id, p.max_domains, p.php_versions, p.cpu_quota_pct, p.memory_max_bytes, p.tasks_max, p.disk_bytes
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.id = ? AND s.status = \'active\'',
            [$subscription_id]
        ) ?? throw new HttpException(422, 'subscription_inactive');

        $allowed_php = json_decode((string) $sub['php_versions'], true) ?: [];
        if ($web_backend !== 'redirect' && !$ctx->isAdmin() && !in_array($php_version, $allowed_php, true)) {
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
            'INSERT INTO vhosts (domain, subscription_id, sys_user, php_version, web_backend, redirect_target, redirect_code, docroot, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'creating\')',
            [$domain, $subscription_id, 'pending', $php_version, $web_backend, $redirect_target, $redirect_code, "/var/www/vhosts/$domain/httpdocs"]
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
            'disk_bytes' => (int) $sub['disk_bytes'],
            'redirect_target' => $redirect_target,
            'redirect_code' => $redirect_code,
        ], $ctx->user_id);

        // Mail: nova domena odmah dobiva i mail domenu (DKIM + zapis u mail_domains) — e-mail
        // računi se mogu kreirati bez ručnog dodavanja domene na Mail ekranu. Samo ako je mail
        // (postfix) instaliran i nije redirect. MORA prije provisionDns: tako DKIM TXT uđe u
        // DNS zonu (lokalnu i CF) u istom prolazu. Best-effort — ne ruši kreiranje web domene.
        $mail_domain_id = null;
        if ($web_backend !== 'redirect') {
            try {
                $mail_domain_id = \ForgePanel\Web\Core\DomainProvision::mailDomain($this->app, $domain, $subscription_id);
            } catch (\Throwable $e) {
                error_log('forgepanel: vhost.create mail provision: ' . $e->getMessage());
            }
        }

        // Auto-DNS: svaka nova domena odmah dobiva komplet zapisa (A/www/mail/MX/SPF/DMARC/CAA + DKIM).
        // Ako je u formi odabran Cloudflare račun → zapiši ih u odgovarajuću CF zonu i poveži
        // vhost s tim računom (cloudflare_zones); inače lokalna BIND zona (ako je DNS instaliran).
        // MORA prije ssl.issue: agentov AutoSSL po toj vezi bira DNS-01 umjesto http-01.
        // Best-effort: greška u DNS-u ne ruši kreiranje vhosta (dovrši se ručno na DNS/CF ekranu).
        $dns = $this->provisionDns($ctx, $request, $vhost_id, $domain, $subscription_id);

        // AutoSSL: svaki novi vhost automatski dobiva certifikat (DNS-01 ako je domena na
        // CF-u — radi i za proxied/wildcard; inače http-01).
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
        Response::ok(['vhost_id' => $vhost_id, 'task_id' => $task_id, 'ssl_task_id' => $ssl_task_id, 'dns' => $dns, 'mail_domain_id' => $mail_domain_id], 202);
    }

    /**
     * Dodaj poddomenu (Plesk-style) — prvorazredni vhost pod matičnom domenom: vlastiti
     * docroot, sistemski user, FPM pool, SSL i uptime proba (sve kroz isti vhost.create task),
     * a DNS se dodaje u POSTOJEĆU infrastrukturu roditelja (lokalna zona i/ili CF zona),
     * ne kao nova zona. Limit broja poddomena ide po pretplati (plan.max_subdomains).
     */
    private function createSubdomain(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $this->requireActiveLicense();
        $parent = $ctx->vhostOr404((int) $request->param('id'));

        // Poddomena se radi samo nad pravom (hostanom) stranicom, ne nad redirectom,
        // i ne ugnježđujemo poddomene (parent ne smije ni sam biti poddomena).
        if ($parent['web_backend'] === 'redirect') {
            throw new HttpException(422, 'parent_not_hostable');
        }
        if ($parent['parent_vhost_id'] !== null) {
            throw new HttpException(422, 'parent_is_subdomain');
        }

        // Labela poddomene: jedna ili više pod-labela (npr. "api" ili "api.v2")
        $label = strtolower(trim($request->str('label') ?? ''));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $label)) {
            throw new HttpException(422, 'invalid_subdomain');
        }
        $domain = $label . '.' . $parent['domain'];
        if (strlen($domain) > 255) {
            throw new HttpException(422, 'invalid_domain');
        }

        $php_version = $request->str('php_version') ?? (string) $parent['php_version'];
        if (!in_array($php_version, self::PHP_VERSIONS, true)) {
            throw new HttpException(422, 'invalid_php_version');
        }
        $web_backend = $request->str('web_backend') === 'nginx_apache' ? 'nginx_apache' : 'nginx';

        // Poddomena pripada istoj pretplati kao matični vhost
        $subscription_id = (int) $parent['subscription_id'];
        $sub = $this->app->db->one(
            'SELECT s.id, p.max_subdomains, p.php_versions, p.cpu_quota_pct, p.memory_max_bytes, p.tasks_max
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.id = ? AND s.status = \'active\'',
            [$subscription_id]
        ) ?? throw new HttpException(422, 'subscription_inactive');

        $allowed_php = json_decode((string) $sub['php_versions'], true) ?: [];
        if (!$ctx->isAdmin() && !in_array($php_version, $allowed_php, true)) {
            throw new HttpException(422, 'php_version_not_in_plan');
        }

        // Limit poddomena po pretplati (admin bez limita)
        $count = $this->app->db->one(
            'SELECT COUNT(*) AS n FROM vhosts WHERE subscription_id = ? AND parent_vhost_id IS NOT NULL',
            [$subscription_id]
        );
        if (!$ctx->isAdmin() && (int) $count['n'] >= (int) $sub['max_subdomains']) {
            throw new HttpException(422, 'plan_subdomain_limit_reached');
        }

        if ($this->app->db->one('SELECT 1 FROM vhosts WHERE domain = ?', [$domain]) !== null) {
            throw new HttpException(409, 'domain_exists');
        }

        $this->app->db->run(
            'INSERT INTO vhosts (domain, subscription_id, parent_vhost_id, sys_user, php_version, web_backend, docroot, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'creating\')',
            [$domain, $subscription_id, (int) $parent['id'], 'pending', $php_version, $web_backend, "/var/www/vhosts/$domain/httpdocs"]
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
            'redirect_target' => null,
            'redirect_code' => 301,
        ], $ctx->user_id);

        // Auto-DNS u POSTOJEĆOJ infrastrukturi roditelja (lokalna zona i/ili CF). MORA prije
        // ssl.issue: agentov AutoSSL po CF vezi bira DNS-01 umjesto http-01. Best-effort.
        $dns = ['local' => false, 'cloudflare' => false];
        try {
            $dns = \ForgePanel\Web\Core\DomainProvision::subdomain(
                $this->app, (int) $parent['id'], (string) $parent['domain'], $label, $vhost_id
            );
        } catch (\Throwable $e) {
            error_log('forgepanel: subdomain.create DNS: ' . $e->getMessage());
        }

        // AutoSSL: samo hostname poddomene (bez www. — ne postoji www.<sub> u DNS-u)
        $contact = $this->setting('acme_email', $ctx->email);
        $ssl_task_id = $this->app->tasks->enqueue('ssl.issue', [
            'hostnames' => [$domain],
            'contact_email' => $contact,
            'vhost_id' => $vhost_id,
        ], $ctx->user_id);
        $this->app->db->run(
            'INSERT INTO ssl_certs (vhost_id, hostname, type, cert_path, key_path, expires_at, status)
             VALUES (?, ?, \'letsencrypt\', ?, ?, NOW(), \'pending\')',
            [$vhost_id, $domain, "/etc/forgepanel/ssl/$domain/fullchain.pem", "/etc/forgepanel/ssl/$domain/privkey.pem"]
        );

        // Eksterni uptime monitoring — i poddomena dobiva HTTPS probu
        $this->app->db->run(
            "INSERT INTO uptime_probes (vhost_id, type, target, interval_s) VALUES (?, 'https', ?, 300)",
            [$vhost_id, $domain]
        );

        $this->app->audit->log($ctx->user_id, $ctx->email, 'subdomain.create', ['domain' => $domain, 'parent' => $parent['domain']], $request->ip);
        Response::ok(['vhost_id' => $vhost_id, 'task_id' => $task_id, 'ssl_task_id' => $ssl_task_id, 'dns' => $dns], 202);
    }

    /**
     * Auto-provisioning DNS-a za novu domenu. Vraća sažetak za UI (toast).
     * @return array<string, mixed>|null
     */
    private function provisionDns(\ForgePanel\Web\Core\AuthContext $ctx, Request $request, int $vhost_id, string $domain, int $subscription_id): ?array
    {
        // 1) UVIJEK lokalna zona — domena mora biti vidljiva i upravljiva pod DNS u panelu
        //    (ako je BIND instaliran). Vrijedi i kad je odabran CF: zadržavamo lokalnu kopiju
        //    (anti vendor-lock) + zapise dodatno guramo na CF.
        $local_zone_id = null;
        try {
            $local_zone_id = \ForgePanel\Web\Core\DomainProvision::localZone($this->app, $domain, $subscription_id);
        } catch (\Throwable $e) {
            error_log('forgepanel: vhost.create local DNS: ' . $e->getMessage());
        }

        $cf_account_id = $request->int('cf_account_id');
        if ($cf_account_id === null || $cf_account_id <= 0) {
            return $local_zone_id !== null ? ['mode' => 'local', 'ok' => true, 'zone_id' => $local_zone_id] : null;
        }

        // 2) Cloudflare: vlasništvo nad računom (klijent ne smije na tuđi), gurni zapise u CF
        //    zonu + poveži vhost (cloudflare_zones → DNS-01/proxy/sync) + badge na DNS ekranu.
        $account = $this->app->db->one(
            'SELECT id, api_token FROM cloudflare_accounts WHERE id = ? AND user_id = ?',
            [$cf_account_id, $ctx->user_id]
        );
        if ($account === null) {
            throw new HttpException(422, 'cloudflare_account_not_found');
        }
        $proxy = (bool) ($request->body['cf_proxy'] ?? true);
        try {
            $res = \ForgePanel\Web\Core\DomainProvision::cloudflare(
                $this->app, $vhost_id, $domain,
                ['id' => (int) $account['id'], 'api_token' => (string) $account['api_token']],
                $proxy
            );
            // Poveži lokalnu zonu s CF računom → badge "Cloudflare" na DNS ekranu
            if ($local_zone_id !== null && ($res['ok'] ?? false)) {
                $this->app->db->run('UPDATE dns_zones SET cf_account_id = ? WHERE id = ?', [(int) $account['id'], $local_zone_id]);
            }
            $res['mode'] = 'cloudflare';
            $res['local_zone_id'] = $local_zone_id;
            return $res;
        } catch (\Throwable $e) {
            error_log('forgepanel: vhost.create CF provision: ' . $e->getMessage());
            return ['mode' => 'cloudflare', 'ok' => false, 'error' => $e->getMessage(), 'local_zone_id' => $local_zone_id];
        }
    }

    private function delete(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        // Brisanje matične domene briše i sve njene poddomene (vlastiti FPM/docroot/SSL/DNS).
        foreach ($this->app->db->all('SELECT id, domain, php_version FROM vhosts WHERE parent_vhost_id = ?', [(int) $vhost['id']]) as $child) {
            $this->app->tasks->enqueue('vhost.delete', [
                'vhost_id' => (int) $child['id'],
                'domain' => $child['domain'],
                'php_version' => $child['php_version'],
            ], $ctx->user_id);
            $this->cleanupCloudflareDns((int) $child['id'], (string) $child['domain']);
            $this->app->db->run('DELETE FROM vhosts WHERE id = ?', [(int) $child['id']]);
            $this->cleanupSubdomainDns((string) $child['domain']);
        }

        $task_id = $this->app->tasks->enqueue('vhost.delete', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'php_version' => $vhost['php_version'],
        ], $ctx->user_id);

        // Brisanje domene = brisanje njenog DNS-a. PRIJE DELETE vhosta (CASCADE briše
        // cloudflare_zones vezu) ukloni zapise s povezane CF zone; zatim lokalnu zonu.
        $this->cleanupCloudflareDns((int) $vhost['id'], (string) $vhost['domain']);
        $this->deleteLocalZone((string) $vhost['domain']);

        $this->app->db->run('DELETE FROM vhosts WHERE id = ?', [$vhost['id']]);
        // Ako je vhost poddomena (staging/subdomena) lokalne zone, ukloni njegov A/AAAA
        // zapis iz matične zone (apex zona je već obrisana gore ako je postojala).
        $this->cleanupSubdomainDns((string) $vhost['domain']);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'vhost.delete', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    /** Obriši lokalnu (BIND) zonu domene + sve njene zapise (dns_records ide CASCADE). */
    private function deleteLocalZone(string $domain): void
    {
        $zone = $this->app->db->one('SELECT id FROM dns_zones WHERE domain = ?', [$domain]);
        if ($zone === null) {
            return;
        }
        try {
            $this->app->agent->call('dns.zone_delete', ['domain' => $domain]);
        } catch (\Throwable $e) {
            error_log('forgepanel: vhost.delete zone_delete: ' . $e->getMessage());
        }
        $this->app->db->run('DELETE FROM dns_zones WHERE id = ?', [(int) $zone['id']]);
    }

    /** Ukloni zapise koje je panel postavio s povezane CF zone (best-effort). */
    private function cleanupCloudflareDns(int $vhost_id, string $domain): void
    {
        try {
            \ForgePanel\Web\Core\DomainProvision::cloudflareCleanup($this->app, $vhost_id, $domain);
        } catch (\Throwable $e) {
            error_log('forgepanel: vhost.delete CF cleanup: ' . $e->getMessage());
        }
    }

    /** Ukloni A/AAAA zapis poddomene iz matične lokalne zone (best-effort). */
    private function cleanupSubdomainDns(string $domain): void
    {
        foreach ($this->app->db->all('SELECT id, domain FROM dns_zones') as $z) {
            $suffix = '.' . $z['domain'];
            if ($domain === $z['domain'] || !str_ends_with($domain, $suffix)) {
                continue;
            }
            $label = substr($domain, 0, -strlen($suffix));
            $del = $this->app->db->run(
                "DELETE FROM dns_records WHERE zone_id = ? AND name = ? AND type IN ('A','AAAA')",
                [(int) $z['id'], $label]
            );
            if ($del->rowCount() > 0) {
                try {
                    \ForgePanel\Web\Core\DnsSync::sync($this->app, (int) $z['id']);
                } catch (\Throwable $e) {
                    error_log('forgepanel: subdomain DNS cleanup sync: ' . $e->getMessage());
                }
            }
            return;
        }
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
