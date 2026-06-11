<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

final class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: \Closure}> */
    private array $routes = [];

    public function add(string $method, string $pattern, \Closure $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m) use (&$params): string {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): never
    {
        $path_matched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $path_matched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $request->route_params = array_combine($route['params'], array_slice($m, 1));
            ($route['handler'])($request);
            Response::error(500, 'handler_no_response');
        }
        Response::error($path_matched ? 405 : 404, $path_matched ? 'method_not_allowed' : 'not_found');
    }
}
