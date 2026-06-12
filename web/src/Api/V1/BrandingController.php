<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * White-label: reseller postavlja vlastiti naziv panela, accent boju, logo
 * i panel domenu — klijent resellera nikad ne vidi ForgePanel brend.
 */
final class BrandingController extends Controller
{
    public function register(Router $router): void
    {
        // Branding po hostu (login ekran ga čita bez autha)
        $router->add('GET', '/api/v1/branding', $this->current(...));
        $router->add('PUT', '/api/v1/branding', $this->update(...));
    }

    /** Branding za trenutni host — javno (login ekran). */
    private function current(Request $request): never
    {
        $host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
        $row = $this->app->db->one(
            "SELECT value FROM settings WHERE `key` = ?",
            ['branding_host_' . $host]
        );
        if ($row !== null) {
            Response::ok(json_decode((string) $row['value'], true));
        }
        Response::ok(['panel_name' => 'ForgePanel', 'accent' => '#f59e0b', 'logo_url' => null]);
    }

    private function update(Request $request): never
    {
        $ctx = $this->ctx($request, 'branding:write');
        $ctx->requireRole('admin', 'reseller');

        $panel_name = trim($request->str('panel_name') ?? 'ForgePanel');
        if ($panel_name === '' || mb_strlen($panel_name) > 64) {
            throw new HttpException(422, 'invalid_panel_name');
        }
        $accent = $request->str('accent') ?? '#f59e0b';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            throw new HttpException(422, 'invalid_accent');
        }
        $panel_host = strtolower(trim($request->str('panel_host') ?? ''));
        if ($panel_host !== '' && !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $panel_host)) {
            throw new HttpException(422, 'invalid_panel_host');
        }
        $logo_url = $request->str('logo_url');
        if ($logo_url !== null && !preg_match('#^https://[\w./%-]{1,300}$#', $logo_url)) {
            throw new HttpException(422, 'invalid_logo_url');
        }

        $branding = ['panel_name' => $panel_name, 'accent' => $accent, 'logo_url' => $logo_url, 'owner_id' => $ctx->user_id];
        // Po hostu (white-label domena) + po vlasniku
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ['branding_owner_' . $ctx->user_id, json_encode($branding)]
        );
        if ($panel_host !== '') {
            $this->app->db->run(
                "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
                ['branding_host_' . $panel_host, json_encode($branding)]
            );
            // AutoSSL za reseller panel domenu
            $this->app->tasks->enqueue('ssl.issue', [
                'hostnames' => [$panel_host],
                'contact_email' => $this->app->config->get('acme_email', $ctx->email),
            ], $ctx->user_id);
        }
        // Veži i na host kojim admin TRENUTNO pristupa — inače current() (čita po
        // hostu) ne nađe branding pa se na refreshu boja vrati na default.
        $current_host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        if ($current_host !== '' && $current_host !== $panel_host) {
            $this->app->db->run(
                "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
                ['branding_host_' . $current_host, json_encode($branding)]
            );
        }
        $this->app->audit->log($ctx->user_id, $ctx->email, 'branding.update', ['panel_name' => $panel_name, 'host' => $panel_host], $request->ip);
        Response::ok($branding);
    }
}
