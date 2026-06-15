<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Sistemske postavke (admin) — žive vrijednosti u settings tablici (override web.ini-ja).
 * Čitaju ih kontroleri preko Controller::setting() (settings → config → default).
 */
final class SettingsController extends Controller
{
    private const KEYS = ['acme_email', 'server_ipv4', 'default_php', 'panel_fqdn'];

    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/settings', $this->index(...));
        $router->add('PUT', '/api/v1/settings', $this->update(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'config:read');
        $ctx->requireRole('admin');
        Response::ok([
            'acme_email'  => $this->setting('acme_email', ''),
            'server_ipv4' => $this->setting('server_ipv4', ''),
            'default_php' => $this->setting('default_php', '8.5'),
            'panel_fqdn'  => $this->setting('panel_fqdn', (string) gethostname()),
        ]);
    }

    private function update(Request $request): never
    {
        $ctx = $this->ctx($request, 'config:write');
        $ctx->requireRole('admin');

        $in = is_array($request->body['settings'] ?? null) ? $request->body['settings'] : [];
        $changed = [];
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $value = trim((string) $in[$key]);
            if ($value === '') {
                continue;
            }
            if (!$this->valid($key, $value)) {
                throw new HttpException(422, 'invalid_' . $key);
            }
            $this->app->db->run(
                "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
                [$key, json_encode($value)]
            );
            $changed[] = $key;
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'settings.update', ['keys' => $changed], $request->ip);
        Response::ok(['changed' => $changed]);
    }

    private function valid(string $key, string $value): bool
    {
        return match ($key) {
            'acme_email'  => (bool) filter_var($value, FILTER_VALIDATE_EMAIL),
            'server_ipv4' => (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
            'default_php' => in_array($value, ['8.1', '8.2', '8.3', '8.4', '8.5'], true),
            'panel_fqdn'  => (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value),
            default       => false,
        };
    }
}
