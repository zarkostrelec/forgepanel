<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\AuthContext;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Javna status stranica za klijente — dostupnost domena, response time i SSL
 * istek bez logina, preko nepogodljivog tokena. Panelovi ovo nemaju; svi plaćaju
 * vanjski uptime servis. Token → subscription mapiranje živi u settings.
 */
final class StatusController extends Controller
{
    public function register(Router $router): void
    {
        // JAVNO (bez autha) — token u URL-u
        $router->add('GET', '/api/v1/status/public/{token}', $this->public(...));
        // Upravljanje (klijent/admin)
        $router->add('GET', '/api/v1/status', $this->current(...));
        $router->add('POST', '/api/v1/status/enable', $this->enable(...));
        $router->add('DELETE', '/api/v1/status', $this->disable(...));
    }

    /** Javni prikaz — bez autha. Token mapira na pretplatu. */
    private function public(Request $request): never
    {
        $token = (string) $request->param('token');
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new HttpException(404, 'not_found');
        }
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = ?", ['statuspage_token_' . $token]);
        if ($row === null) {
            throw new HttpException(404, 'not_found');
        }
        $subscription_id = (int) json_decode((string) $row['value'], true);

        $sites = $this->app->db->all(
            "SELECT v.domain, v.status,
                    (SELECT p.last_status FROM uptime_probes p WHERE p.vhost_id = v.id ORDER BY p.id LIMIT 1) AS uptime,
                    (SELECT p.response_ms FROM uptime_probes p WHERE p.vhost_id = v.id ORDER BY p.id LIMIT 1) AS response_ms,
                    (SELECT DATEDIFF(MIN(c.expires_at), NOW()) FROM ssl_certs c WHERE c.vhost_id = v.id AND c.status = 'active') AS ssl_days
             FROM vhosts v WHERE v.subscription_id = ? ORDER BY v.domain",
            [$subscription_id]
        );

        // Branding vlasnika pretplate (white-label) ako postoji
        $owner = $this->app->db->one(
            'SELECT s.user_id FROM subscriptions s WHERE s.id = ?',
            [$subscription_id]
        );
        $branding = null;
        if ($owner !== null) {
            $b = $this->app->db->one("SELECT value FROM settings WHERE `key` = ?", ['branding_owner_' . $owner['user_id']]);
            $branding = $b === null ? null : json_decode((string) $b['value'], true);
        }

        Response::ok([
            'panel_name' => $branding['panel_name'] ?? 'ForgePanel',
            'accent' => $branding['accent'] ?? '#10b981',
            'generated_at' => date('c'),
            'sites' => $sites,
        ]);
    }

    /** Trenutni status-token klijenta (ili null). */
    private function current(Request $request): never
    {
        $ctx = $this->ctx($request, 'monitoring:read');
        $sid = $this->subscriptionId($ctx);
        $row = $sid === null ? null : $this->app->db->one("SELECT value FROM settings WHERE `key` = ?", ['statuspage_sub_' . $sid]);
        if ($row === null) {
            Response::ok(['enabled' => false, 'token' => null]);
        }
        $token = (string) json_decode((string) $row['value'], true);
        Response::ok(['enabled' => true, 'token' => $token, 'url' => $this->statusUrl($request, $token)]);
    }

    private function enable(Request $request): never
    {
        $ctx = $this->ctx($request, 'monitoring:write');
        $sid = $this->subscriptionId($ctx) ?? throw new HttpException(422, 'no_subscription');

        $existing = $this->app->db->one("SELECT value FROM settings WHERE `key` = ?", ['statuspage_sub_' . $sid]);
        $token = $existing === null ? bin2hex(random_bytes(16)) : (string) json_decode((string) $existing['value'], true);

        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ['statuspage_sub_' . $sid, json_encode($token)]
        );
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ['statuspage_token_' . $token, json_encode($sid)]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'status.enable', ['subscription_id' => $sid], $request->ip);
        Response::ok(['enabled' => true, 'token' => $token, 'url' => $this->statusUrl($request, $token)]);
    }

    private function disable(Request $request): never
    {
        $ctx = $this->ctx($request, 'monitoring:write');
        $sid = $this->subscriptionId($ctx) ?? throw new HttpException(422, 'no_subscription');
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = ?", ['statuspage_sub_' . $sid]);
        if ($row !== null) {
            $token = (string) json_decode((string) $row['value'], true);
            $this->app->db->run("DELETE FROM settings WHERE `key` = ?", ['statuspage_token_' . $token]);
            $this->app->db->run("DELETE FROM settings WHERE `key` = ?", ['statuspage_sub_' . $sid]);
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'status.disable', ['subscription_id' => $sid], $request->ip);
        Response::ok();
    }

    /** Pretplata pozivatelja (prva aktivna); admin dobiva svoju default pretplatu. */
    private function subscriptionId(AuthContext $ctx): ?int
    {
        if ($ctx->subscription_ids !== []) {
            return $ctx->subscription_ids[0];
        }
        return $ctx->isAdmin() ? $this->adminSubscription($ctx) : null;
    }

    private function statusUrl(Request $request, string $token): string
    {
        $host = $request->host !== '' ? $request->host : 'localhost';
        return $request->scheme . '://' . $host . '/status/' . $token;
    }
}
