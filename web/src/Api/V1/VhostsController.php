<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class VhostsController extends Controller
{
    private const PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4'];

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/vhosts', $this->index(...));
        $router->add('POST', '/api/v1/vhosts', $this->create(...));
        $router->add('GET', '/api/v1/vhosts/{id}', $this->show(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}', $this->delete(...));
        $router->add('PUT', '/api/v1/vhosts/{id}/php', $this->setPhp(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:read');
        if ($ctx->isAdmin()) {
            Response::ok($this->app->db->all('SELECT * FROM vhosts ORDER BY domain'));
        }
        if ($ctx->subscription_ids === []) {
            Response::ok([]);
        }
        $placeholders = implode(',', array_fill(0, count($ctx->subscription_ids), '?'));
        Response::ok($this->app->db->all(
            "SELECT * FROM vhosts WHERE subscription_id IN ($placeholders) ORDER BY domain",
            $ctx->subscription_ids
        ));
    }

    private function show(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:read');
        Response::ok($ctx->vhostOr404((int) $request->param('id')));
    }

    private function create(Request $request): never
    {
        $ctx = $this->ctx($request, 'vhosts:write');

        $domain = strtolower(trim($request->str('domain') ?? ''));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new HttpException(422, 'invalid_domain');
        }
        $php_version = $request->str('php_version', '8.4');
        if (!in_array($php_version, self::PHP_VERSIONS, true)) {
            throw new HttpException(422, 'invalid_php_version');
        }

        $subscription_id = $request->int('subscription_id') ?? ($ctx->subscription_ids[0] ?? null);
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
        $contact = $this->app->config->get('acme_email', $ctx->email);
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
