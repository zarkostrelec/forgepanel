<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Deliverability suite — RBL monitor, DMARC reports, SPF/DKIM/DMARC validator. */
final class DeliverabilityController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/deliverability/rbl', $this->rbl(...));
        $router->add('POST', '/api/v1/deliverability/validate', $this->validate(...));
        $router->add('GET', '/api/v1/deliverability/dmarc', $this->dmarcReports(...));
    }

    private function rbl(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:read');
        $ctx->requireRole('admin');
        $ip = $request->query('ip') ?? $this->serverIp();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new HttpException(422, 'invalid_ip');
        }
        Response::ok($this->app->agent->call('deliverability.check', ['action' => 'rbl', 'ip' => $ip], timeout_s: 60));
    }

    private function validate(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:read');
        $domain = strtolower(trim($request->str('domain') ?? ''));
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new HttpException(422, 'invalid_domain');
        }
        // Klijent smije validirati samo svoje mail domene
        if (!$ctx->isAdmin()) {
            $owned = $this->app->db->one(
                'SELECT 1 FROM mail_domains m WHERE m.domain = ? AND m.subscription_id IN ('
                . (count($ctx->subscription_ids) ? implode(',', array_fill(0, count($ctx->subscription_ids), '?')) : 'NULL') . ')',
                [$domain, ...$ctx->subscription_ids]
            );
            if ($owned === null) {
                throw new HttpException(404, 'not_found');
            }
        }
        Response::ok($this->app->agent->call('deliverability.check', ['action' => 'validate', 'domain' => $domain], timeout_s: 30));
    }

    private function dmarcReports(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:read');
        $rows = $ctx->isAdmin()
            ? $this->app->db->all('SELECT d.*, m.domain FROM dmarc_reports d JOIN mail_domains m ON m.id = d.mail_domain_id ORDER BY d.id DESC LIMIT 100')
            : ($ctx->subscription_ids === [] ? [] : $this->app->db->all(
                'SELECT d.*, m.domain FROM dmarc_reports d JOIN mail_domains m ON m.id = d.mail_domain_id
                 WHERE m.subscription_id IN (' . implode(',', array_fill(0, count($ctx->subscription_ids), '?')) . ')
                 ORDER BY d.id DESC LIMIT 100',
                $ctx->subscription_ids
            ));
        Response::ok($rows);
    }

    private function serverIp(): string
    {
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = 'server_ipv4'");
        $ip = $row === null ? null : json_decode((string) $row['value'], true);
        return is_string($ip) ? $ip : '127.0.0.1';
    }
}
