<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * Migrator: cPanel cpmove import. analyze = dry-run (pokaži što će se kreirati),
 * import = kreiranje ForgePanel entiteta. Anti vendor-lock filozofija.
 */
final class MigratorController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('POST', '/api/v1/migrator/cpanel/upload', $this->upload(...));
        $router->add('GET', '/api/v1/migrator/cpanel/{token}/analyze', $this->analyze(...));
        $router->add('POST', '/api/v1/migrator/cpanel/{token}/import', $this->import(...));
    }

    /** Upload cpmove arhive (multipart) — samo admin. */
    private function upload(Request $request): never
    {
        $ctx = $this->admin($request);
        $file = $_FILES['archive'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'upload_failed');
        }
        $name = basename((string) $file['name']);
        if (!preg_match('/\.(tar|tar\.gz|tgz)$/', $name)) {
            throw new HttpException(422, 'invalid_archive_type');
        }

        $token = bin2hex(random_bytes(12));
        $dest = '/var/lib/forgepanel/migrations';
        // Agent kreira direktorij s ispravnim dozvolama; web ovdje samo bilježi
        $stored = "$dest/$token-" . preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
        if (!is_dir($dest) && !@mkdir($dest, 0o700, true)) {
            throw new HttpException(500, 'migration_dir_unavailable');
        }
        if (!move_uploaded_file((string) $file['tmp_name'], $stored)
            && !rename((string) $file['tmp_name'], $stored)
        ) {
            throw new HttpException(500, 'store_failed');
        }
        $this->app->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?)",
            ['migration_' . $token, json_encode(['archive_path' => $stored, 'by' => $ctx->user_id])]
        );
        Response::ok(['token' => $token], 201);
    }

    private function analyze(Request $request): never
    {
        $ctx = $this->admin($request);
        $archive = $this->archivePath($request);
        $result = $this->app->agent->call('migrator.cpanel_parse', ['archive_path' => $archive], timeout_s: 600);
        Response::ok($result);
    }

    private function import(Request $request): never
    {
        $ctx = $this->admin($request);
        $archive = $this->archivePath($request);
        $subscription_id = $request->int('subscription_id') ?? throw new HttpException(400, 'subscription_required');
        $sub = $this->app->db->one('SELECT id FROM subscriptions WHERE id = ?', [$subscription_id])
            ?? throw new HttpException(422, 'invalid_subscription');

        $parsed = $this->app->agent->call('migrator.cpanel_parse', ['archive_path' => $archive], timeout_s: 600);

        $report = ['vhosts' => [], 'databases' => [], 'mailboxes' => [], 'skipped' => []];

        // Domene → vhostovi (kreiranje kroz task, kao u VhostsController)
        foreach ($parsed['domains'] as $domain) {
            if ($this->app->db->one('SELECT 1 FROM vhosts WHERE domain = ?', [$domain]) !== null) {
                $report['skipped'][] = "vhost $domain (postoji)";
                continue;
            }
            $this->app->db->run(
                "INSERT INTO vhosts (domain, subscription_id, sys_user, php_version, docroot, status)
                 VALUES (?, ?, 'pending', '8.4', ?, 'creating')",
                [$domain, $subscription_id, "/var/www/vhosts/$domain/httpdocs"]
            );
            $vhost_id = $this->app->db->lastId();
            $this->app->db->run('UPDATE vhosts SET sys_user = ? WHERE id = ?', ['vh_' . $vhost_id, $vhost_id]);
            $this->app->tasks->enqueue('vhost.create', [
                'vhost_id' => $vhost_id, 'domain' => $domain, 'php_version' => '8.4',
            ], $ctx->user_id);
            $report['vhosts'][] = $domain;
        }

        // Baze
        foreach ($parsed['databases'] as $db_name) {
            if ($this->app->db->one('SELECT 1 FROM db_databases WHERE name = ?', [$db_name]) !== null) {
                $report['skipped'][] = "baza $db_name (postoji)";
                continue;
            }
            try {
                $this->app->agent->call('db.create', ['name' => $db_name]);
                $this->app->db->run('INSERT INTO db_databases (subscription_id, name) VALUES (?, ?)', [$subscription_id, $db_name]);
                $report['databases'][] = $db_name;
            } catch (\Throwable $e) {
                $report['skipped'][] = "baza $db_name: " . $e->getMessage();
            }
        }

        $this->app->audit->log($ctx->user_id, $ctx->email, 'migrator.cpanel_import', [
            'vhosts' => count($report['vhosts']),
            'databases' => count($report['databases']),
        ], $request->ip);
        Response::ok($report, 202);
    }

    private function archivePath(Request $request): string
    {
        $token = $request->param('token');
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            throw new HttpException(404, 'not_found');
        }
        $row = $this->app->db->one('SELECT value FROM settings WHERE `key` = ?', ['migration_' . $token])
            ?? throw new HttpException(404, 'not_found');
        $data = json_decode((string) $row['value'], true);
        return (string) ($data['archive_path'] ?? '');
    }

    private function admin(Request $request): \ForgePanel\Web\Core\AuthContext
    {
        $ctx = $this->ctx($request, 'migrator:write');
        $ctx->requireRole('admin');
        return $ctx;
    }
}
