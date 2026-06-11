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

    /**
     * Vlasništvo vhosta + normalizacija relativnog patha unutar vhost roota.
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function resolve(Request $request, string $scope): array
    {
        $ctx = $this->ctx($request, $scope);
        $vhost = $ctx->vhostOr404((int) $request->param('id'));

        $relative = (string) ($request->str('path') ?? '/');
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
