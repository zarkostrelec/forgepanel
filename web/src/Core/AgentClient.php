<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Klijent za forge-agentd UNIX socket. Web sloj kroz ovo šalje SAMO
 * predefinirane operacije — nikakve shell komande ne postoje u web sloju.
 */
final class AgentClient
{
    public function __construct(private readonly string $socket_path = '/run/forgepanel/agent.sock')
    {
    }

    /**
     * Sinkroni poziv kratke operacije.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function call(string $op, array $params = [], int $timeout_s = 20): array
    {
        $socket = @stream_socket_client('unix://' . $this->socket_path, $errno, $errstr, 5);
        if ($socket === false) {
            throw new HttpException(503, 'agent_unavailable');
        }
        stream_set_timeout($socket, $timeout_s);

        fwrite($socket, json_encode(['op' => $op, 'params' => $params], JSON_UNESCAPED_SLASHES) . "\n");
        $raw = fgets($socket, 10_485_760);
        fclose($socket);

        if (!is_string($raw)) {
            throw new HttpException(504, 'agent_timeout');
        }
        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new HttpException(502, 'agent_bad_response');
        }
        if (($response['ok'] ?? false) !== true) {
            throw new HttpException(422, 'agent: ' . ($response['error'] ?? 'unknown'));
        }
        return $response['data'] ?? [];
    }
}
