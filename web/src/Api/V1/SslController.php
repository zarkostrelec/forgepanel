<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

final class SslController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/ssl', $this->index(...));
        $router->add('POST', '/api/v1/vhosts/{id}/ssl/renew', $this->renew(...));
        $router->add('POST', '/api/v1/vhosts/{id}/ssl/custom', $this->installCustom(...));
    }

    /** Pregled svih certifikata s istekom (filtrirano po vlasništvu). */
    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'ssl:read');
        if ($ctx->isAdmin()) {
            Response::ok($this->app->db->all('SELECT * FROM ssl_certs ORDER BY expires_at'));
        }
        if ($ctx->subscription_ids === []) {
            Response::ok([]);
        }
        $placeholders = implode(',', array_fill(0, count($ctx->subscription_ids), '?'));
        Response::ok($this->app->db->all(
            "SELECT c.* FROM ssl_certs c JOIN vhosts v ON v.id = c.vhost_id
             WHERE v.subscription_id IN ($placeholders) ORDER BY c.expires_at",
            $ctx->subscription_ids
        ));
    }

    private function renew(Request $request): never
    {
        $ctx = $this->ctx($request, 'ssl:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $task_id = $this->app->tasks->enqueue('ssl.issue', [
            'hostnames' => [$vhost['domain'], 'www.' . $vhost['domain']],
            'contact_email' => $this->setting('acme_email', $ctx->email),
            'vhost_id' => (int) $vhost['id'],
        ], $ctx->user_id);

        // Ručni renew vraća domenu na AutoSSL (i s custom certa natrag na Let's Encrypt)
        $this->app->db->run(
            'UPDATE ssl_certs SET auto_renew = 1 WHERE vhost_id = ? AND hostname = ?',
            [$vhost['id'], $vhost['domain']]
        );
        $this->app->audit->log($ctx->user_id, $ctx->email, 'ssl.renew', ['domain' => $vhost['domain']], $request->ip);
        Response::ok(['task_id' => $task_id], 202);
    }

    /** Ručna instalacija kupljenog/vlastitog certifikata (PEM cert + key + opcionalni CA chain). */
    private function installCustom(Request $request): never
    {
        $ctx = $this->ctx($request, 'ssl:write');
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $cert_pem = trim($request->str('cert_pem') ?? '');
        $key_pem = trim($request->str('key_pem') ?? '');
        if ($cert_pem === '' || $key_pem === '') {
            throw new HttpException(422, 'cert_and_key_required');
        }

        // Agent validira (ključ↔cert, domena, istek, chain) i instalira — sinkrono, odmah znamo rezultat
        $result = $this->app->agent->call('ssl.install_custom', [
            'domain' => $vhost['domain'],
            'cert_pem' => $cert_pem,
            'key_pem' => $key_pem,
            'chain_pem' => $request->str('chain_pem') ?? '',
        ], timeout_s: 30);

        // Custom cert se NE obnavlja automatski — AutoSSL ga ne smije pregaziti (auto_renew = 0)
        $ssl_dir = '/etc/forgepanel/ssl/' . $vhost['domain'];
        $existing = $this->app->db->one(
            'SELECT id FROM ssl_certs WHERE vhost_id = ? AND hostname = ?',
            [$vhost['id'], $vhost['domain']]
        );
        if ($existing !== null) {
            $this->app->db->run(
                "UPDATE ssl_certs SET type = 'custom', cert_path = ?, key_path = ?, expires_at = ?,
                     auto_renew = 0, status = 'active', last_error = NULL WHERE id = ?",
                ["$ssl_dir/fullchain.pem", "$ssl_dir/privkey.pem", $result['expires_at'], $existing['id']]
            );
        } else {
            $this->app->db->run(
                "INSERT INTO ssl_certs (vhost_id, hostname, type, cert_path, key_path, expires_at, auto_renew, status)
                 VALUES (?, ?, 'custom', ?, ?, ?, 0, 'active')",
                [$vhost['id'], $vhost['domain'], "$ssl_dir/fullchain.pem", "$ssl_dir/privkey.pem", $result['expires_at']]
            );
        }

        $this->app->audit->log($ctx->user_id, $ctx->email, 'ssl.install_custom', [
            'domain' => $vhost['domain'],
            'issuer' => $result['issuer'] ?? '?',
            'expires_at' => $result['expires_at'],
        ], $request->ip);
        Response::ok($result, 201);
    }
}
