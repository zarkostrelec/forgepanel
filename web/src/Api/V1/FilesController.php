<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\AuthContext;
use ForgePanel\Web\Core\HttpException;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/**
 * File manager API — svaki path se razrješava RELATIVNO unutar vhost roota
 * korisnika; agent radi drugu (realpath) provjeru. Dvostruka obrana.
 */
final class FilesController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('POST', '/api/v1/vhosts/{id}/files/list', $this->list(...));
        $router->add('POST', '/api/v1/vhosts/{id}/files/read', $this->read(...));
        $router->add('POST', '/api/v1/vhosts/{id}/files/write', $this->write(...));
        $router->add('POST', '/api/v1/vhosts/{id}/files/mkdir', $this->mkdir(...));
        $router->add('POST', '/api/v1/vhosts/{id}/files/delete', $this->delete(...));
        $router->add('POST', '/api/v1/vhosts/{id}/files/chmod', $this->chmod(...));
        $router->add('POST', '/api/v1/vhosts/{id}/files/upload', $this->upload(...));
        $router->add('GET', '/api/v1/vhosts/{id}/files/download', $this->download(...));
    }

    private function list(Request $request): never
    {
        [$vhost, $path] = $this->resolve($request, 'files:read');
        Response::ok($this->app->agent->call('fs.list', ['path' => $path]));
    }

    private function read(Request $request): never
    {
        [$vhost, $path] = $this->resolve($request, 'files:read');
        Response::ok($this->app->agent->call('fs.read', ['path' => $path]));
    }

    private function write(Request $request): never
    {
        [$vhost, $path] = $this->resolve($request, 'files:write');
        $content_b64 = $request->str('content_b64') ?? throw new HttpException(400, 'content_b64_required');
        Response::ok($this->app->agent->call('fs.write', [
            'path' => $path,
            'content_b64' => $content_b64,
            'owner' => $vhost['sys_user'],
        ]));
    }

    private function mkdir(Request $request): never
    {
        [$vhost, $path] = $this->resolve($request, 'files:write');
        Response::ok($this->app->agent->call('fs.mkdir', ['path' => $path, 'owner' => $vhost['sys_user']]));
    }

    private function delete(Request $request): never
    {
        [$vhost, $path] = $this->resolve($request, 'files:write');
        Response::ok($this->app->agent->call('fs.delete', ['path' => $path]));
    }

    private function chmod(Request $request): never
    {
        [$vhost, $path] = $this->resolve($request, 'files:write');
        $mode = $request->str('mode') ?? throw new HttpException(400, 'mode_required');
        Response::ok($this->app->agent->call('fs.chmod', ['path' => $path, 'mode' => $mode]));
    }

    /** Upload: multipart form-data — polja `path` (ciljni direktorij) i `file`. */
    private function upload(Request $request): never
    {
        [$vhost, $dir_path] = $this->resolve($request, 'files:write');

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(422, 'upload_failed: err_' . ($file['error'] ?? 'none'));
        }
        $name = basename((string) $file['name']);
        if ($name === '' || str_contains($name, "\0") || strlen($name) > 255) {
            throw new HttpException(422, 'invalid_filename');
        }

        $content = file_get_contents((string) $file['tmp_name']);
        if ($content === false) {
            throw new HttpException(500, 'upload_read_failed');
        }
        Response::ok($this->app->agent->call('fs.write', [
            'path' => $dir_path . '/' . $name,
            'content_b64' => base64_encode($content),
            'owner' => $vhost['sys_user'],
        ], timeout_s: 120));
    }

    /** Download: streamanje sadržaja s Content-Disposition (path u query stringu). */
    private function download(Request $request): never
    {
        [$vhost, $path] = $this->resolve($request, 'files:read', $request->query('path') ?? '/');
        $data = $this->app->agent->call('fs.read', ['path' => $path], timeout_s: 120);
        $content = base64_decode((string) ($data['content'] ?? ''), true);
        if ($content === false) {
            throw new HttpException(502, 'agent_bad_content');
        }
        Response::securityHeaders();
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($content));
        header('Content-Disposition: attachment; filename="' . rawurlencode(basename($path)) . '"');
        echo $content;
        exit;
    }

    /**
     * Vlasništvo vhosta + normalizacija relativnog patha unutar vhost roota.
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function resolve(Request $request, string $scope, ?string $path_override = null): array
    {
        $ctx = $this->ctx($request, $scope);
        // Delegirani developer s 'files' permisijom dolazi do file managera
        $vhost = $ctx->vhostOr404((int) $request->param('id'), 'files');

        $relative = $path_override ?? (string) ($request->str('path') ?? '/');
        if (str_contains($relative, "\0")) {
            throw new HttpException(422, 'invalid_path');
        }
        // Leksička normalizacija: izbaci . i .., zabranjen izlazak iznad roota
        $parts = [];
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new HttpException(422, 'path_escape');
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        $root = '/var/www/vhosts/' . $vhost['domain'];
        return [$vhost, rtrim($root . '/' . implode('/', $parts), '/')];
    }
}
