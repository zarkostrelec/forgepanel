<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\AuthContext;
use ForgePanel\Web\Core\DnsSync;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class MailController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/mail/status', $this->status(...));
        $router->add('POST', '/api/v1/mail/setup', $this->setup(...));
        $router->add('POST', '/api/v1/mail/webmail', $this->webmailSetup(...));
        $router->add('GET', '/api/v1/mail/domains', $this->domains(...));
        $router->add('POST', '/api/v1/mail/domains', $this->createDomain(...));
        $router->add('DELETE', '/api/v1/mail/domains/{id}', $this->deleteDomain(...));
        $router->add('GET', '/api/v1/mail/domains/{id}/mailboxes', $this->mailboxes(...));
        $router->add('POST', '/api/v1/mail/domains/{id}/mailboxes', $this->createMailbox(...));
        $router->add('PUT', '/api/v1/mail/domains/{id}/mailboxes/{mid}', $this->updateMailbox(...));
        $router->add('DELETE', '/api/v1/mail/domains/{id}/mailboxes/{mid}', $this->deleteMailbox(...));
        $router->add('GET', '/api/v1/mail/domains/{id}/aliases', $this->aliases(...));
        $router->add('POST', '/api/v1/mail/domains/{id}/aliases', $this->createAlias(...));
        $router->add('DELETE', '/api/v1/mail/domains/{id}/aliases/{aid}', $this->deleteAlias(...));
    }

    private function status(Request $request): never
    {
        $this->ctx($request, 'mail:read');
        $webmail = $this->app->db->one("SELECT value FROM settings WHERE `key` = 'webmail'");
        Response::ok([
            'installed' => $this->mailInstalled(),
            'webmail' => $webmail === null ? null : json_decode((string) $webmail['value'], true),
        ]);
    }

    /** Jednokratna instalacija mail stacka — samo admin, ide kao task. */
    private function setup(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        $ctx->requireRole('admin');
        if ($this->mailInstalled()) {
            throw new HttpException(409, 'mail_already_installed');
        }
        $task_id = $this->app->tasks->enqueue('mail.setup', [], $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'mail.setup', null, $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    /** Roundcube webmail na zasebnom vhostu — admin, task + AutoSSL task. */
    private function webmailSetup(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        $ctx->requireRole('admin');
        if (!$this->mailInstalled()) {
            throw new HttpException(409, 'mail_not_installed');
        }

        $panel_fqdn = $this->app->config->get('panel_fqdn', (string) gethostname());
        $hostname = strtolower(trim($request->str('hostname') ?? "webmail.$panel_fqdn"));
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($hostname, '.')) {
            throw new HttpException(422, 'invalid_hostname');
        }

        $task_id = $this->app->tasks->enqueue('mail.webmail_setup', ['hostname' => $hostname], $ctx->user_id);
        $ssl_task_id = $this->app->tasks->enqueue('ssl.issue', [
            'hostnames' => [$hostname],
            'contact_email' => $this->app->config->get('acme_email', $ctx->email),
        ], $ctx->user_id);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'mail.webmail_setup', ['hostname' => $hostname], $request->ip);
        Response::ok(['task_id' => $task_id, 'ssl_task_id' => $ssl_task_id, 'hostname' => $hostname], 202);
    }

    private function domains(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:read');
        if ($ctx->isAdmin()) {
            Response::ok($this->app->db->all('SELECT * FROM mail_domains ORDER BY domain'));
        }
        if ($ctx->subscription_ids === []) {
            Response::ok([]);
        }
        $placeholders = implode(',', array_fill(0, count($ctx->subscription_ids), '?'));
        Response::ok($this->app->db->all(
            "SELECT * FROM mail_domains WHERE subscription_id IN ($placeholders) ORDER BY domain",
            $ctx->subscription_ids
        ));
    }

    private function createDomain(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        if (!$this->mailInstalled()) {
            throw new HttpException(409, 'mail_not_installed');
        }

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
        if ($this->app->db->one('SELECT 1 FROM mail_domains WHERE domain = ?', [$domain]) !== null) {
            throw new HttpException(409, 'domain_exists');
        }

        // DKIM ključ per domena, automatski
        $dkim = $this->app->agent->call('mail.domain_add', ['domain' => $domain], timeout_s: 60);

        $this->app->db->run(
            'INSERT INTO mail_domains (domain, subscription_id, dkim_selector, dkim_txt) VALUES (?, ?, ?, ?)',
            [$domain, $subscription_id, $dkim['selector'] ?? 'forge', $dkim['dkim_txt'] ?? null]
        );
        $domain_id = $this->app->db->lastId();

        // Ako panel hosta DNS zonu: DKIM TXT ide u zonu automatski
        $zone = $this->app->db->one('SELECT id FROM dns_zones WHERE domain = ?', [$domain]);
        if ($zone !== null && isset($dkim['dkim_txt'])) {
            $this->app->db->run(
                "INSERT INTO dns_records (zone_id, name, type, content, ttl) VALUES (?, ?, 'TXT', ?, 3600)",
                [$zone['id'], ($dkim['selector'] ?? 'forge') . '._domainkey', $dkim['dkim_txt']]
            );
            try {
                DnsSync::sync($this->app, (int) $zone['id']);
            } catch (\Throwable $e) {
                error_log('mail: DKIM DNS sync nije uspio za ' . $domain . ': ' . $e->getMessage());
            }
        }

        $this->app->audit->log($ctx->user_id, $ctx->email, 'mail.domain_create', ['domain' => $domain], $request->ip);
        Response::ok(['id' => $domain_id, 'domain' => $domain, 'dkim_txt' => $dkim['dkim_txt'] ?? null], 201);
    }

    private function deleteDomain(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        $domain = $this->domainOr404($ctx, (int) $request->param('id'));

        $this->app->agent->call('mail.domain_delete', ['domain' => $domain['domain']], timeout_s: 120);
        $this->app->db->run('DELETE FROM mail_domains WHERE id = ?', [$domain['id']]);
        $this->app->audit->log($ctx->user_id, $ctx->email, 'mail.domain_delete', ['domain' => $domain['domain']], $request->ip);
        Response::ok();
    }

    private function mailboxes(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:read');
        $domain = $this->domainOr404($ctx, (int) $request->param('id'));
        Response::ok($this->app->db->all(
            'SELECT id, local_part, quota_bytes, status FROM mailboxes WHERE mail_domain_id = ? ORDER BY local_part',
            [$domain['id']]
        ));
    }

    private function createMailbox(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        $domain = $this->domainOr404($ctx, (int) $request->param('id'));

        $local_part = strtolower(trim($request->str('local_part') ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $local_part)) {
            throw new HttpException(422, 'invalid_local_part');
        }
        $password = $request->str('password') ?? '';
        if (strlen($password) < 10) {
            throw new HttpException(422, 'password_too_short');
        }
        $quota_bytes = max(104857600, min(107374182400, $request->int('quota_bytes', 1073741824) ?? 1073741824));

        // Plan limit: max_mailboxes
        $limit = $this->app->db->one(
            'SELECT p.max_mailboxes,
                    (SELECT COUNT(*) FROM mailboxes m JOIN mail_domains md ON md.id = m.mail_domain_id
                     WHERE md.subscription_id = s.id) AS used
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.id = ?',
            [$domain['subscription_id']]
        );
        if (!$ctx->isAdmin() && $limit !== null && (int) $limit['used'] >= (int) $limit['max_mailboxes']) {
            throw new HttpException(422, 'plan_mailbox_limit_reached');
        }
        if ($this->app->db->one(
            'SELECT 1 FROM mailboxes WHERE mail_domain_id = ? AND local_part = ?',
            [$domain['id'], $local_part]
        ) !== null) {
            throw new HttpException(409, 'mailbox_exists');
        }

        // SHA512-CRYPT — Dovecot ga čita direktno iz baze (bez sinkronizacije)
        $hash = crypt($password, '$6$' . bin2hex(random_bytes(8)) . '$');
        $this->app->db->run(
            'INSERT INTO mailboxes (mail_domain_id, local_part, password_hash, quota_bytes) VALUES (?, ?, ?, ?)',
            [$domain['id'], $local_part, $hash, $quota_bytes]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'mail.mailbox_create', ['address' => "$local_part@{$domain['domain']}"], $request->ip);
        Response::ok(['id' => $this->app->db->lastId(), 'address' => "$local_part@{$domain['domain']}"], 201);
    }

    private function updateMailbox(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        $domain = $this->domainOr404($ctx, (int) $request->param('id'));
        $mailbox = $this->app->db->one(
            'SELECT * FROM mailboxes WHERE id = ? AND mail_domain_id = ?',
            [(int) $request->param('mid'), $domain['id']]
        ) ?? throw new HttpException(404, 'not_found');

        $password = $request->str('password');
        if ($password !== null) {
            if (strlen($password) < 10) {
                throw new HttpException(422, 'password_too_short');
            }
            $this->app->db->run(
                'UPDATE mailboxes SET password_hash = ? WHERE id = ?',
                [crypt($password, '$6$' . bin2hex(random_bytes(8)) . '$'), $mailbox['id']]
            );
        }
        $quota_bytes = $request->int('quota_bytes');
        if ($quota_bytes !== null) {
            $this->app->db->run(
                'UPDATE mailboxes SET quota_bytes = ? WHERE id = ?',
                [max(104857600, min(107374182400, $quota_bytes)), $mailbox['id']]
            );
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'mail.mailbox_update', ['mailbox_id' => $mailbox['id']], $request->ip);
        Response::ok();
    }

    private function deleteMailbox(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        $domain = $this->domainOr404($ctx, (int) $request->param('id'));
        $deleted = $this->app->db->run(
            'DELETE FROM mailboxes WHERE id = ? AND mail_domain_id = ?',
            [(int) $request->param('mid'), $domain['id']]
        )->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404, 'not_found');
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'mail.mailbox_delete', ['domain' => $domain['domain']], $request->ip);
        Response::ok();
    }

    private function aliases(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:read');
        $domain = $this->domainOr404($ctx, (int) $request->param('id'));
        Response::ok($this->app->db->all(
            'SELECT id, source, destination FROM mail_aliases WHERE mail_domain_id = ? ORDER BY source',
            [$domain['id']]
        ));
    }

    private function createAlias(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        $domain = $this->domainOr404($ctx, (int) $request->param('id'));

        $source_local = strtolower(trim($request->str('source') ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $source_local)) {
            throw new HttpException(422, 'invalid_source');
        }
        $source = "$source_local@{$domain['domain']}";

        $destination = strtolower(trim($request->str('destination') ?? ''));
        if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(422, 'invalid_destination');
        }

        $this->app->db->run(
            'INSERT INTO mail_aliases (mail_domain_id, source, destination) VALUES (?, ?, ?)',
            [$domain['id'], $source, $destination]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'mail.alias_create', ['source' => $source], $request->ip);
        Response::ok(['id' => $this->app->db->lastId(), 'source' => $source], 201);
    }

    private function deleteAlias(Request $request): never
    {
        $ctx = $this->ctx($request, 'mail:write');
        $domain = $this->domainOr404($ctx, (int) $request->param('id'));
        $deleted = $this->app->db->run(
            'DELETE FROM mail_aliases WHERE id = ? AND mail_domain_id = ?',
            [(int) $request->param('aid'), $domain['id']]
        )->rowCount();
        if ($deleted === 0) {
            throw new HttpException(404, 'not_found');
        }
        Response::ok();
    }

    private function mailInstalled(): bool
    {
        return $this->app->db->one(
            "SELECT 1 FROM components WHERE name = 'postfix' AND status = 'installed'"
        ) !== null;
    }

    /** @return array<string, mixed> */
    private function domainOr404(AuthContext $ctx, int $id): array
    {
        $domain = $this->app->db->one('SELECT * FROM mail_domains WHERE id = ?', [$id]);
        if ($domain === null) {
            throw new HttpException(404, 'not_found');
        }
        $ctx->requireSubscription((int) $domain['subscription_id']);
        return $domain;
    }
}
