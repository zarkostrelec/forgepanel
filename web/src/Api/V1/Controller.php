<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\App;
use ForgePanel\Web\Core\AuthContext;
use ForgePanel\Web\Core\Request;
use ForgePanel\Web\Core\Router;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    abstract public function register(Router $router): void;

    /** Autentikacija + scope provjera — zajednički middleware svake zaštićene rute. */
    protected function ctx(Request $request, string $scope): AuthContext
    {
        $ctx = $this->app->auth->requireAuth($request);
        $ctx->requireScope($scope);
        return $ctx;
    }
}
