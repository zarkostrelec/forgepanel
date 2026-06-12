<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\Auth;
use ForgePanel\Web\Core\HttpClient;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Provisioning API za billing (WHMCS/Blesta). account.* operacije +
 * webhook eventi. Traži scope `provisioning:write` na API tokenu.
 */
final class ProvisioningController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('POST', '/api/v1/provisioning/account/create', $this->create(...));
        $router->add('POST', '/api/v1/provisioning/account/suspend', $this->suspend(...));
        $router->add('POST', '/api/v1/provisioning/account/unsuspend', $this->unsuspend(...));
        $router->add('POST', '/api/v1/provisioning/account/terminate', $this->terminate(...));
        $router->add('POST', '/api/v1/provisioning/account/changepackage', $this->changePackage(...));
    }

    private function create(Request $request): never
    {
        $ctx = $this->prov($request);
        $email = strtolower(trim($request->str('email') ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'invalid_email');
        }
        $plan_id = $request->int('plan_id') ?? throw new HttpException(400, 'plan_required');
        $plan = $this->app->db->one('SELECT id FROM plans WHERE id = ?', [$plan_id])
            ?? throw new HttpException(422, 'invalid_plan');
        if ($this->app->db->one('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
            throw new HttpException(409, 'email_exists');
        }

        $password = $request->str('password') ?? bin2hex(random_bytes(12));
        $client_role = $this->app->db->one("SELECT id FROM roles WHERE name = 'client'");
        $this->app->db->run(
            'INSERT INTO users (email, password_hash, role_id) VALUES (?, ?, ?)',
            [$email, Auth::hashPassword($password), $client_role['id']]
        );
        $user_id = $this->app->db->lastId();
        $this->app->db->run('INSERT INTO subscriptions (user_id, plan_id) VALUES (?, ?)', [$user_id, $plan['id']]);
        $subscription_id = $this->app->db->lastId();

        $this->emitWebhook('account.created', ['user_id' => $user_id, 'subscription_id' => $subscription_id, 'email' => $email]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'provisioning.create', ['email' => $email], $request->ip);
        Response::ok(['user_id' => $user_id, 'subscription_id' => $subscription_id, 'password' => $password], 201);
    }

    private function suspend(Request $request): never
    {
        $this->changeStatus($request, 'suspended', 'account.suspended');
    }

    private function unsuspend(Request $request): never
    {
        $this->changeStatus($request, 'active', 'account.unsuspended');
    }

    private function changeStatus(Request $request, string $status, string $event): never
    {
        $ctx = $this->prov($request);
        $sub = $this->subscription($request);

        $this->app->db->run('UPDATE subscriptions SET status = ? WHERE id = ?', [$status, $sub['id']]);
        $this->app->db->run('UPDATE users SET status = ? WHERE id = ?', [$status, $sub['user_id']]);

        // Suspend/unsuspend svih vhostova računa (kroz vhost.suspend task)
        $action = $status === 'suspended' ? 'suspend' : 'unsuspend';
        foreach ($this->app->db->all('SELECT * FROM vhosts WHERE subscription_id = ?', [$sub['id']]) as $vhost) {
            $this->app->tasks->enqueue('vhost.suspend', [
                'vhost_id' => (int) $vhost['id'], 'domain' => $vhost['domain'],
                'php_version' => $vhost['php_version'], 'action' => $action,
            ], $ctx->user_id);
            $this->app->db->run('UPDATE vhosts SET status = ? WHERE id = ?', [$status === 'suspended' ? 'suspended' : 'active', $vhost['id']]);
        }

        $this->emitWebhook($event, ['subscription_id' => $sub['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'provisioning.' . $action, ['subscription_id' => $sub['id']], $request->ip);
        Response::ok();
    }

    private function terminate(Request $request): never
    {
        $ctx = $this->prov($request);
        $sub = $this->subscription($request);

        // Briše sve vhostove (kroz task), pa subscription + usera
        foreach ($this->app->db->all('SELECT * FROM vhosts WHERE subscription_id = ?', [$sub['id']]) as $vhost) {
            $this->app->tasks->enqueue('vhost.delete', [
                'vhost_id' => (int) $vhost['id'], 'domain' => $vhost['domain'], 'php_version' => $vhost['php_version'],
            ], $ctx->user_id);
            $this->app->db->run('DELETE FROM vhosts WHERE id = ?', [$vhost['id']]);
        }
        $this->app->db->run("UPDATE subscriptions SET status = 'terminated' WHERE id = ?", [$sub['id']]);

        $this->emitWebhook('account.terminated', ['subscription_id' => $sub['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'provisioning.terminate', ['subscription_id' => $sub['id']], $request->ip);
        Response::ok();
    }

    private function changePackage(Request $request): never
    {
        $ctx = $this->prov($request);
        $sub = $this->subscription($request);
        $plan_id = $request->int('plan_id') ?? throw new HttpException(400, 'plan_required');
        $this->app->db->one('SELECT 1 FROM plans WHERE id = ?', [$plan_id]) ?? throw new HttpException(422, 'invalid_plan');

        $this->app->db->run('UPDATE subscriptions SET plan_id = ? WHERE id = ?', [$plan_id, $sub['id']]);
        $this->emitWebhook('account.changepackage', ['subscription_id' => $sub['id'], 'plan_id' => $plan_id]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'provisioning.changepackage', ['subscription_id' => $sub['id'], 'plan_id' => $plan_id], $request->ip);
        Response::ok();
    }

    /** @return array<string, mixed> */
    private function subscription(Request $request): array
    {
        $id = $request->int('subscription_id') ?? throw new HttpException(400, 'subscription_required');
        return $this->app->db->one('SELECT * FROM subscriptions WHERE id = ?', [$id])
            ?? throw new HttpException(404, 'not_found');
    }

    /** @param array<string, mixed> $payload */
    private function emitWebhook(string $event, array $payload): void
    {
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = 'provisioning_webhook'");
        $url = $row === null ? null : json_decode((string) $row['value'], true);
        // Anti-SSRF: samo https i samo javne mete (ne loopback/private/metadata IP).
        if (!is_string($url) || !HttpClient::isSafePublicUrl($url)) {
            return;
        }
        $body = json_encode(['event' => $event, 'data' => $payload, 'ts' => time()], JSON_UNESCAPED_SLASHES);
        $ch = curl_init($url);
        HttpClient::apply($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
        ]);
        @curl_exec($ch);
        curl_close($ch);
    }

    private function prov(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        return $this->ctx($request, 'provisioning:write');
    }
}
