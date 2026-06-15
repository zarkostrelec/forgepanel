<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** ufw + fail2ban UI — samo admin. */
final class FirewallController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/firewall/rules', $this->rules(...));
        $router->add('POST', '/api/v1/firewall/rules', $this->addRule(...));
        $router->add('DELETE', '/api/v1/firewall/rules', $this->delRule(...));
        $router->add('GET', '/api/v1/firewall/jails', $this->jails(...));
        $router->add('POST', '/api/v1/firewall/ban', $this->ban(...));
        $router->add('POST', '/api/v1/firewall/unban', $this->unban(...));
        $router->add('POST', '/api/v1/firewall/country-block', $this->countryBlock(...));
        $router->add('GET', '/api/v1/vhosts/{id}/waf', $this->wafState(...));
        $router->add('POST', '/api/v1/vhosts/{id}/waf', $this->waf(...));
        $router->add('GET', '/api/v1/vhosts/{id}/waf/log', $this->wafLog(...));
        $router->add('POST', '/api/v1/vhosts/{id}/waf/whitelist', $this->wafWhitelistAdd(...));
        $router->add('DELETE', '/api/v1/vhosts/{id}/waf/whitelist/{rule}', $this->wafWhitelistDel(...));
    }

    /** WAF stanje: engine on/off, paranoia i whitelistana pravila. */
    private function wafState(Request $request): never
    {
        $ctx = $this->ctx($request, 'firewall:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $row = $this->app->db->one("SELECT value FROM settings WHERE `key` = ?", ['waf_' . $vhost['id']]);
        $cfg = $row === null ? [] : json_decode((string) $row['value'], true);
        $whitelist = array_column(
            $this->app->db->all(
                "SELECT rule_id FROM waf_rules WHERE vhost_id = ? AND action = 'whitelist' AND rule_id <> 'engine' ORDER BY rule_id",
                [(int) $vhost['id']]
            ),
            'rule_id'
        );
        Response::ok([
            'domain' => $vhost['domain'],
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'paranoia' => (int) ($cfg['paranoia'] ?? 1),
            'whitelist' => $whitelist,
        ]);
    }

    /** Blokirani zahtjevi (ModSecurity log) za vhost. */
    private function wafLog(Request $request): never
    {
        $ctx = $this->ctx($request, 'firewall:read');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        Response::ok($this->app->agent->call('waf.rule', [
            'action' => 'log', 'domain' => $vhost['domain'],
        ], timeout_s: 30));
    }

    private function wafWhitelistAdd(Request $request): never
    {
        $ctx = $this->ctx($request, 'firewall:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $rule_id = (string) ($request->str('rule_id') ?? '');
        if (!preg_match('/^\d{1,9}$/', $rule_id)) {
            throw new HttpException(422, 'invalid_rule_id');
        }
        $result = $this->app->agent->call('waf.rule', [
            'action' => 'whitelist_add', 'domain' => $vhost['domain'], 'rule_id' => $rule_id,
        ], timeout_s: 30);
        $this->app->db->run(
            "INSERT INTO waf_rules (vhost_id, rule_id, action) VALUES (?, ?, 'whitelist')",
            [(int) $vhost['id'], $rule_id]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'firewall.waf_whitelist', ['domain' => $vhost['domain'], 'rule_id' => $rule_id], $request->ip);
        Response::ok($result);
    }

    private function wafWhitelistDel(Request $request): never
    {
        $ctx = $this->ctx($request, 'firewall:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $rule_id = (string) $request->param('rule');
        if (!preg_match('/^\d{1,9}$/', $rule_id)) {
            throw new HttpException(422, 'invalid_rule_id');
        }
        $result = $this->app->agent->call('waf.rule', [
            'action' => 'whitelist_remove', 'domain' => $vhost['domain'], 'rule_id' => $rule_id,
        ], timeout_s: 30);
        $this->app->db->run(
            "DELETE FROM waf_rules WHERE vhost_id = ? AND rule_id = ? AND action = 'whitelist'",
            [(int) $vhost['id'], $rule_id]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'firewall.waf_unwhitelist', ['domain' => $vhost['domain'], 'rule_id' => $rule_id], $request->ip);
        Response::ok($result);
    }

    /** Country blocking (ipset GeoIP) — admin. */
    private function countryBlock(Request $request): never
    {
        $ctx = $this->admin($request);
        $countries = $request->body['countries'] ?? [];
        if (!is_array($countries) || count($countries) > 50) {
            throw new HttpException(422, 'invalid_countries');
        }
        foreach ($countries as $cc) {
            if (!is_string($cc) || !preg_match('/^[a-z]{2}$/i', $cc)) {
                throw new HttpException(422, 'invalid_country_code');
            }
        }
        $task_id = $this->app->tasks->enqueue('country.block', ['countries' => array_values($countries)], $ctx->user_id);
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES ('blocked_countries', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [json_encode(array_values($countries))]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'firewall.country_block', ['countries' => $countries], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    /** WAF per-vhost (admin ili vlasnik vhosta). */
    private function waf(Request $request): never
    {
        $ctx = $this->ctx($request, 'firewall:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));
        $enabled = (bool) ($request->body['enabled'] ?? false);
        $paranoia = max(1, min(4, $request->int('paranoia', 1) ?? 1));
        $task_id = $this->app->tasks->enqueue('waf.toggle', [
            'vhost_id' => (int) $vhost['id'],
            'domain' => $vhost['domain'],
            'enabled' => $enabled,
            'paranoia' => $paranoia,
        ], $ctx->user_id);
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ['waf_' . $vhost['id'], json_encode(['enabled' => $enabled, 'paranoia' => $paranoia])]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'firewall.waf', ['domain' => $vhost['domain'], 'enabled' => $enabled], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    private function rules(Request $request): never
    {
        $this->admin($request);
        Response::ok($this->app->agent->call('firewall.action', ['action' => 'ufw_status']));
    }

    private function addRule(Request $request): never
    {
        $ctx = $this->admin($request);
        $port = $request->int('port') ?? throw new HttpException(400, 'port_required');
        $this->app->agent->call('firewall.action', [
            'action' => 'ufw_allow',
            'port' => $port,
            'proto' => $request->str('proto', 'tcp'),
            'from' => $request->str('from'),
        ]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'firewall.allow', ['port' => $port], $request->ip);
        Response::ok();
    }

    private function delRule(Request $request): never
    {
        $ctx = $this->admin($request);
        $port = $request->int('port') ?? throw new HttpException(400, 'port_required');
        $this->app->agent->call('firewall.action', ['action' => 'ufw_deny', 'port' => $port, 'proto' => $request->str('proto', 'tcp')]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'firewall.deny', ['port' => $port], $request->ip);
        Response::ok();
    }

    private function jails(Request $request): never
    {
        $this->admin($request);
        Response::ok($this->app->agent->call('firewall.action', ['action' => 'f2b_jails']));
    }

    private function ban(Request $request): never
    {
        $ctx = $this->admin($request);
        $this->app->agent->call('firewall.action', [
            'action' => 'f2b_ban',
            'jail' => $request->str('jail') ?? throw new HttpException(400, 'jail_required'),
            'ip' => $request->str('ip') ?? throw new HttpException(400, 'ip_required'),
        ]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'firewall.ban', ['ip' => $request->str('ip')], $request->ip);
        Response::ok();
    }

    private function unban(Request $request): never
    {
        $ctx = $this->admin($request);
        $this->app->agent->call('firewall.action', [
            'action' => 'f2b_unban',
            'jail' => $request->str('jail') ?? throw new HttpException(400, 'jail_required'),
            'ip' => $request->str('ip') ?? throw new HttpException(400, 'ip_required'),
        ]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'firewall.unban', ['ip' => $request->str('ip')], $request->ip);
        Response::ok();
    }

    private function admin(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        $ctx = $this->ctx($request, 'firewall:write');
        $ctx->requireRole('admin');
        return $ctx;
    }
}
