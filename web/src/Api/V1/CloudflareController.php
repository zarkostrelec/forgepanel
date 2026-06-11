<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\CloudflareClient;
use ForgePanel\Web\Core\Crypto;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Cloudflare integracija: token (enkriptiran), DNS sync, proxy toggle, cache purge. */
final class CloudflareController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/cloudflare/account', $this->account(...));
        $router->add('POST', '/api/v1/cloudflare/account', $this->connect(...));
        $router->add('DELETE', '/api/v1/cloudflare/account', $this->disconnect(...));
        $router->add('GET', '/api/v1/cloudflare/zones', $this->zones(...));
        $router->add('POST', '/api/v1/vhosts/{id}/cloudflare/sync', $this->syncVhost(...));
        $router->add('POST', '/api/v1/vhosts/{id}/cloudflare/purge', $this->purge(...));
        $router->add('PUT', '/api/v1/cloudflare/records/{rid}/proxy', $this->toggleProxy(...));
    }

    private function account(Request $request): never
    {
        $ctx = $this->ctx($request, 'cloudflare:read');
        $row = $this->app->db->one('SELECT id, status FROM cloudflare_accounts WHERE user_id = ?', [$ctx->user_id]);
        Response::ok(['connected' => $row !== null, 'status' => $row['status'] ?? null]);
    }

    private function connect(Request $request): never
    {
        $ctx = $this->ctx($request, 'cloudflare:write');
        $token = trim($request->str('api_token') ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{20,120}$/', $token)) {
            throw new HttpException(422, 'invalid_token');
        }

        // Verifikacija tokena prije spremanja
        $verify = (new CloudflareClient($token))->verify();
        if (!$verify['ok']) {
            throw new HttpException(422, 'cloudflare_token_invalid');
        }

        $crypto = new Crypto($this->app->config);
        $encrypted = $crypto->encrypt($token);
        $existing = $this->app->db->one('SELECT id FROM cloudflare_accounts WHERE user_id = ?', [$ctx->user_id]);
        if ($existing === null) {
            $this->app->db->run(
                "INSERT INTO cloudflare_accounts (user_id, api_token, status) VALUES (?, ?, 'active')",
                [$ctx->user_id, $encrypted]
            );
        } else {
            $this->app->db->run("UPDATE cloudflare_accounts SET api_token = ?, status = 'active' WHERE id = ?", [$encrypted, $existing['id']]);
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'cloudflare.connect', null, $request->ip);
        Response::ok(['connected' => true]);
    }

    private function disconnect(Request $request): never
    {
        $ctx = $this->ctx($request, 'cloudflare:write');
        $this->app->db->run('DELETE FROM cloudflare_accounts WHERE user_id = ?', [$ctx->user_id]);
        Response::ok();
    }

    private function zones(Request $request): never
    {
        $ctx = $this->ctx($request, 'cloudflare:read');
        Response::ok($this->client($ctx)->zones());
    }

    /** Piše vhost A/MX/SPF/DKIM/DMARC zapise u CF zonu. */
    private function syncVhost(Request $request): never
    {
        $ctx = $this->ctx($request, 'cloudflare:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $zone_id = $request->str('zone_id') ?? throw new HttpException(400, 'zone_id_required');
        if (!preg_match('/^[a-f0-9]{32}$/', $zone_id)) {
            throw new HttpException(422, 'invalid_zone_id');
        }

        $cf = $this->client($ctx);
        $server_ip = $this->serverIp();
        $proxy = (bool) ($request->body['proxy'] ?? true);

        $created = [];
        // A za root + www (proxied po izboru)
        foreach (['@' => $vhost['domain'], 'www' => 'www.' . $vhost['domain']] as $name => $fqdn) {
            try {
                $cf->createRecord($zone_id, 'A', $fqdn, $server_ip, $proxy);
                $created[] = "A $fqdn";
            } catch (\Throwable $e) {
                $created[] = "A $fqdn: " . $e->getMessage();
            }
        }
        // Mail zapisi nikad ne idu proxied
        $mail = $this->app->db->one('SELECT dkim_selector, dkim_txt FROM mail_domains WHERE domain = ?', [$vhost['domain']]);
        if ($mail !== null) {
            try {
                $cf->createRecord($zone_id, 'A', 'mail.' . $vhost['domain'], $server_ip, false);
                $cf->createRecord($zone_id, 'MX', $vhost['domain'], 'mail.' . $vhost['domain'], false);
                $cf->createRecord($zone_id, 'TXT', $vhost['domain'], 'v=spf1 a mx ~all', false);
                if ($mail['dkim_txt']) {
                    $cf->createRecord($zone_id, 'TXT', $mail['dkim_selector'] . '._domainkey.' . $vhost['domain'], $mail['dkim_txt'], false);
                }
                $created[] = 'mail zapisi (MX/SPF/DKIM)';
            } catch (\Throwable $e) {
                $created[] = 'mail: ' . $e->getMessage();
            }
        }

        // Zapamti vezu vhost ↔ CF zona
        $account = $this->app->db->one('SELECT id FROM cloudflare_accounts WHERE user_id = ?', [$ctx->user_id]);
        $this->app->db->run(
            "INSERT INTO cloudflare_zones (vhost_id, account_id, zone_id, dns_mode, proxy_default)
             VALUES (?, ?, ?, 'cloudflare', ?)
             ON DUPLICATE KEY UPDATE zone_id = VALUES(zone_id), dns_mode = 'cloudflare'",
            [$vhost['id'], $account['id'], $zone_id, (int) $proxy]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'cloudflare.sync', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['created' => $created]);
    }

    private function purge(Request $request): never
    {
        $ctx = $this->ctx($request, 'cloudflare:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $zone = $this->app->db->one('SELECT zone_id FROM cloudflare_zones WHERE vhost_id = ?', [$vhost['id']])
            ?? throw new HttpException(409, 'vhost_not_on_cloudflare');

        $urls = $request->body['urls'] ?? null;
        $this->client($ctx)->purgeCache($zone['zone_id'], is_array($urls) && $urls !== [] ? $urls : null);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'cloudflare.purge', ['domain' => $vhost['domain']], $request->ip);
        Response::ok();
    }

    private function toggleProxy(Request $request): never
    {
        $ctx = $this->ctx($request, 'cloudflare:write');
        $record_id = $request->param('rid');
        $zone_id = $request->str('zone_id') ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $record_id) || !preg_match('/^[a-f0-9]{32}$/', $zone_id)) {
            throw new HttpException(422, 'invalid_id');
        }
        $this->client($ctx)->setProxied($zone_id, $record_id, (bool) ($request->body['proxied'] ?? false));
        Response::ok();
    }

    private function client(\ForgePanel\Web\Core\AuthContext $ctx): CloudflareClient
    {
        $row = $this->app->db->one('SELECT api_token FROM cloudflare_accounts WHERE user_id = ?', [$ctx->user_id])
            ?? throw new HttpException(409, 'cloudflare_not_connected');
        $token = (new Crypto($this->app->config))->decrypt((string) $row['api_token']);
        return new CloudflareClient($token);
    }

    private function serverIp(): string
    {
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = 'server_ipv4'");
        $ip = $row === null ? null : json_decode((string) $row['value'], true);
        return is_string($ip) ? $ip : '127.0.0.1';
    }
}
