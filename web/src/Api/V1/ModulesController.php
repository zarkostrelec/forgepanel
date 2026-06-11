<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\ModuleRegistry;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Response;
use ForgePanel\Web\Core\Router;

/** Plugin SDK — pregled instaliranih modula trećih strana (admin). */
final class ModulesController extends Controller
{
    public function register(Router $router): void
    {
        $router->add('GET', '/api/v1/modules', $this->index(...));
    }

    private function index(Request $request): never
    {
        $ctx = $this->ctx($request, 'updates:read');
        $ctx->requireRole('admin');
        Response::ok((new ModuleRegistry())->all());
    }
}
