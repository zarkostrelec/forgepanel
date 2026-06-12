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

    /**
     * Adminova default pretplata. Svježa instalacija nema nijedan plan ni
     * pretplatu, a resursi (vhost/mail/db/dns/docker) zahtijevaju subscription_id
     * (model plan→pretplata→resurs). Za admina se idempotentno kreira globalni
     * plan "Admin" (praktički neograničen) + aktivna pretplata — admin kreira
     * resurse odmah nakon instalacije, bez ručnog postavljanja planova.
     */
    protected function adminSubscription(AuthContext $ctx): int
    {
        $existing = $this->app->db->one(
            "SELECT id FROM subscriptions WHERE user_id = ? AND status = 'active' ORDER BY id LIMIT 1",
            [$ctx->user_id]
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $plan = $this->app->db->one("SELECT id FROM plans WHERE name = 'Admin' AND owner_user_id IS NULL");
        if ($plan === null) {
            $this->app->db->run(
                "INSERT INTO plans (owner_user_id, name, disk_bytes, max_domains, max_mailboxes,
                                    max_databases, php_versions, features, cpu_quota_pct, memory_max_bytes, tasks_max)
                 VALUES (NULL, 'Admin', 1099511627776, 10000, 10000, 10000, ?, '{}', 400, 4294967296, 1024)",
                [json_encode(['8.1', '8.2', '8.3', '8.4', '8.5'])]
            );
            $plan_id = $this->app->db->lastId();
        } else {
            $plan_id = (int) $plan['id'];
        }

        $this->app->db->run(
            "INSERT INTO subscriptions (user_id, plan_id, status) VALUES (?, ?, 'active')",
            [$ctx->user_id, $plan_id]
        );
        return $this->app->db->lastId();
    }
}
