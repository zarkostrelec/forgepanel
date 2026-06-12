<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\AuthContext;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class DnsController extends Controller
{
    private const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/dns/zones', $this->zones(...));
        $router->add('POST', '/api/v1/dns/zones', $this->createZone(...));
        $router->add('DELETE', '/api/v1/dns/zones/{id}', $this->deleteZone(...));
        $router->add('GET', '/api/v1/dns/zones/{id}/records', $this->records(...));
        $router->add('POST', '/api/v1/dns/zones/{id}/records', $this->createRecord(...));
        $router->add('DELETE', '/api/v1/dns/zones/{id}/records/{rid}', $this->deleteRecord(...));
    }

    private function zones(Request $request): never
    {
        $ctx = $this->ctx($request, 'dns:read');
        if ($ctx->isAdmin()) {
            Response::ok($this->app->db->all('SELECT * FROM dns_zones ORDER BY domain'));
        }
        if ($ctx->subscription_ids === []) {
            Response::ok([]);
        }
        $placeholders = implode(',', array_fill(0, count($ctx->subscription_ids), '?'));
        Response::ok($this->app->db->all(
            "SELECT * FROM dns_zones WHERE subscription_id IN ($placeholders) ORDER BY domain",
            $ctx->subscription_ids
        ));
    }

    private function createZone(Request $request): never
    {
        $ctx = $this->ctx($request, 'dns:write');

        $domain = strtolower(trim($request->str('domain') ?? ''));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new HttpException(422, 'invalid_domain');
        }
        $subscription_id = $request->int('subscription_id')
            ?? ($ctx->subscription_ids[0] ?? ($ctx->isAdmin() ? $this->adminSubscription($ctx) : null));
        if ($subscription_id === null) {
            throw new HttpException(422, 'subscription_required');
        }
        $ctx->requireSubscription($subscription_id);
        if ($this->app->db->one('SELECT 1 FROM dns_zones WHERE domain = ?', [$domain]) !== null) {
            throw new HttpException(409, 'zone_exists');
        }

        $this->app->db->run(
            'INSERT INTO dns_zones (domain, subscription_id, serial) VALUES (?, ?, ?)',
            [$domain, $subscription_id, time()]
        );
        $zone_id = $this->app->db->lastId();

        // Auto-generiranje kompletne zone: A/www/MX/SPF/DMARC/CAA
        $server_ip = $this->serverIp();
        $defaults = [
            ['@', 'A', $server_ip, 3600, null],
            ['www', 'A', $server_ip, 3600, null],
            ['mail', 'A', $server_ip, 3600, null],
            ['@', 'MX', "mail.$domain", 3600, 10],
            ['@', 'TXT', 'v=spf1 a mx ~all', 3600, null],
            ['_dmarc', 'TXT', "v=DMARC1; p=quarantine; rua=mailto:dmarc@$domain", 3600, null],
            ['@', 'CAA', '0 issue "letsencrypt.org"', 3600, null],
        ];
        foreach ($defaults as [$name, $type, $content, $ttl, $prio]) {
            $this->app->db->run(
                'INSERT INTO dns_records (zone_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)',
                [$zone_id, $name, $type, $content, $ttl, $prio]
            );
        }

        try {
            $this->syncZone($zone_id);
        } catch (\Throwable $e) {
            $this->app->db->run('DELETE FROM dns_zones WHERE id = ?', [$zone_id]);
            throw $e;
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'dns.zone_create', ['domain' => $domain], $request->ip);
        Response::ok(['id' => $zone_id, 'domain' => $domain], 201);
    }

    private function deleteZone(Request $request): never
    {
        $ctx = $this->ctx($request, 'dns:write');
        $zone = $this->zoneOr404($ctx, (int) $request->param('id'));

        $this->app->agent->call('dns.zone_delete', ['domain' => $zone['domain']]);
        $this->app->db->run('DELETE FROM dns_zones WHERE id = ?', [$zone['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'dns.zone_delete', ['domain' => $zone['domain']], $request->ip);
        Response::ok();
    }

    private function records(Request $request): never
    {
        $ctx = $this->ctx($request, 'dns:read');
        $zone = $this->zoneOr404($ctx, (int) $request->param('id'));
        Response::ok($this->app->db->all(
            'SELECT id, name, type, content, ttl, prio FROM dns_records WHERE zone_id = ? ORDER BY type, name',
            [$zone['id']]
        ));
    }

    private function createRecord(Request $request): never
    {
        $ctx = $this->ctx($request, 'dns:write');
        $zone = $this->zoneOr404($ctx, (int) $request->param('id'));

        $name = trim($request->str('name') ?? '@') ?: '@';
        $type = strtoupper(trim($request->str('type') ?? ''));
        $content = trim($request->str('content') ?? '');
        $ttl = max(60, min(604800, $request->int('ttl', 3600) ?? 3600));
        $prio = $request->int('prio');

        if (!in_array($type, self::RECORD_TYPES, true)) {
            throw new HttpException(422, 'invalid_type');
        }
        if ($name !== '@' && !preg_match('/^[a-z0-9_*][a-z0-9_.*-]{0,62}$/i', $name)) {
            throw new HttpException(422, 'invalid_name');
        }
        if ($content === '' || strlen($content) > 1024 || preg_match('/[\r\n]/', $content)) {
            throw new HttpException(422, 'invalid_content');
        }

        $this->app->db->run(
            'INSERT INTO dns_records (zone_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)',
            [$zone['id'], $name, $type, $content, $ttl, $prio]
        );
        $record_id = $this->app->db->lastId();
        try {
            $this->syncZone((int) $zone['id']);
        } catch (\Throwable $e) {
            // named-checkzone odbio zapis — vrati DB u prethodno stanje
            $this->app->db->run('DELETE FROM dns_records WHERE id = ?', [$record_id]);
            throw $e;
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'dns.record_create', ['zone' => $zone['domain'], 'type' => $type, 'name' => $name], $request->ip);
        Response::ok(['id' => $record_id], 201);
    }

    private function deleteRecord(Request $request): never
    {
        $ctx = $this->ctx($request, 'dns:write');
        $zone = $this->zoneOr404($ctx, (int) $request->param('id'));

        $record = $this->app->db->one(
            'SELECT * FROM dns_records WHERE id = ? AND zone_id = ?',
            [(int) $request->param('rid'), $zone['id']]
        ) ?? throw new HttpException(404, 'not_found');

        $this->app->db->run('DELETE FROM dns_records WHERE id = ?', [$record['id']]);
        try {
            $this->syncZone((int) $zone['id']);
        } catch (\Throwable $e) {
            $this->app->db->run(
                'INSERT INTO dns_records (zone_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)',
                [$zone['id'], $record['name'], $record['type'], $record['content'], $record['ttl'], $record['prio']]
            );
            throw $e;
        }
        Response::ok();
    }

    /** Bump seriala + puni rewrite zone kroz agent (named-checkzone je završni sudac). */
    private function syncZone(int $zone_id): void
    {
        \ForgePanel\Web\Core\DnsSync::sync($this->app, $zone_id);
    }

    private function serverIp(): string
    {
        $row = $this->app->db->one('SELECT value FROM settings WHERE `key` = ?', ['server_ipv4']);
        $ip = $row === null ? null : json_decode((string) $row['value'], true);
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '127.0.0.1';
    }

    /** @return array<string, mixed> */
    private function zoneOr404(AuthContext $ctx, int $id): array
    {
        $zone = $this->app->db->one('SELECT * FROM dns_zones WHERE id = ?', [$id]);
        if ($zone === null) {
            throw new HttpException(404, 'not_found');
        }
        $ctx->requireSubscription((int) $zone['subscription_id']);
        return $zone;
    }
}
