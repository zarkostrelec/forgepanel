<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

final class Audit
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @param array<string, mixed>|null $detail */
    public function log(?int $user_id, string $actor, string $action, ?array $detail = null, ?string $ip = null): void
    {
        $this->db->run(
            'INSERT INTO audit_log (user_id, actor, action, detail, ip) VALUES (?, ?, ?, ?, ?)',
            [$user_id, $actor, $action, $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE), $ip]
        );
    }
}
