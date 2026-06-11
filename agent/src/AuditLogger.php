<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

final class AuditLogger
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed>|null $detail */
    public function log(string $actor, string $action, ?array $detail = null): void
    {
        try {
            $this->db->run(
                'INSERT INTO audit_log (actor, action, detail) VALUES (?, ?, ?)',
                [$actor, $action, $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            // Audit nikad ne smije srušiti operaciju, ali gubitak zapisa mora biti vidljiv.
            error_log('forge-agentd audit fail: ' . $e->getMessage());
        }
    }
}
