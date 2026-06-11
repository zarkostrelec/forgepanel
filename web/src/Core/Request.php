<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

final class Request
{
    /** @var array<string, string> */
    public array $route_params = [];

    /** @param array<string, mixed> $body */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $body,
        public readonly string $ip,
        public readonly string $user_agent,
        public readonly ?string $bearer_token,
    ) {
    }

    public static function fromGlobals(): self
    {
        $raw = file_get_contents('php://input');
        $body = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        // multipart/form-data (file upload) — PHP ga parsira u $_POST/$_FILES
        if ($body === [] && $_POST !== []) {
            $body = $_POST;
        }

        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $bearer = preg_match('/^Bearer\s+(\S+)$/', $auth, $m) ? $m[1] : null;

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'),
            body: $body,
            ip: $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            user_agent: $_SERVER['HTTP_USER_AGENT'] ?? '',
            bearer_token: $bearer,
        );
    }

    public function str(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? $default;
        return $value === null ? null : (string) $value;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->body[$key] ?? $default;
        return $value === null ? null : (int) $value;
    }

    public function param(string $key): string
    {
        return $this->route_params[$key] ?? throw new HttpException(500, 'missing_route_param');
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? $default;
        return $value === null ? null : (string) $value;
    }
}
