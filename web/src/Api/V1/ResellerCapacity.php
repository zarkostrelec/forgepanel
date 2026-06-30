<?php

declare(strict_types=1);

namespace ForgePanel\Web\Api\V1;

use ForgePanel\Web\Core\AuthContext;
use ForgePanel\Web\Core\HttpException;

/**
 * Dijeljena reseller-kvota logika (poglavlje 4): vlasništvo plana + provjera da
 * reseller ne proda više nego što mu vlastiti paket dopušta. Koriste je svi
 * ulazi koji kreiraju/mijenjaju pretplate (UsersController, ProvisioningController)
 * da gate bude na JEDNOM mjestu — inače billing/provisioning put zaobiđe limit.
 *
 * Zahtijeva da klasa koja koristi trait ima `protected readonly App $app`
 * (sve API kontrolere ga nasljeđuju iz Controller).
 */
trait ResellerCapacity
{
    /** Reseller smije koristiti samo globalni (owner NULL) ili vlastiti plan. */
    protected function assertPlanOwnership(AuthContext $ctx, int $plan_id): void
    {
        $plan = $this->app->db->one('SELECT owner_user_id FROM plans WHERE id = ?', [$plan_id]);
        if ($plan === null) {
            throw new HttpException(422, 'invalid_plan');
        }
        if (!$ctx->isAdmin() && $plan['owner_user_id'] !== null && (int) $plan['owner_user_id'] !== $ctx->user_id) {
            throw new HttpException(403, 'plan_not_yours');
        }
    }

    /** Ukupni limit resellera (zbroj njegovih aktivnih pretplata-paketa) ili null ako nema paket. */
    protected function resellerCeiling(int $reseller_id): ?array
    {
        $row = $this->app->db->one(
            "SELECT COALESCE(SUM(p.max_domains),0) AS max_domains, COALESCE(SUM(p.max_mailboxes),0) AS max_mailboxes,
                    COALESCE(SUM(p.max_databases),0) AS max_databases, COALESCE(SUM(p.disk_bytes),0) AS disk_bytes,
                    COUNT(*) AS n
             FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? AND s.status = 'active'",
            [$reseller_id]
        );
        return ($row === null || (int) $row['n'] === 0) ? null : [
            'max_domains' => (int) $row['max_domains'], 'max_mailboxes' => (int) $row['max_mailboxes'],
            'max_databases' => (int) $row['max_databases'], 'disk_bytes' => (int) $row['disk_bytes'],
        ];
    }

    /**
     * Već raspodijeljeno klijentima resellera (zbroj limita planova njihovih aktivnih
     * pretplata). $exclude_user_id se izostavi (npr. pri izmjeni plana tog klijenta).
     */
    protected function resellerAllocated(int $reseller_id, int $exclude_user_id = 0): array
    {
        $sql = "SELECT COALESCE(SUM(p.max_domains),0) AS max_domains, COALESCE(SUM(p.max_mailboxes),0) AS max_mailboxes,
                    COALESCE(SUM(p.max_databases),0) AS max_databases, COALESCE(SUM(p.disk_bytes),0) AS disk_bytes
             FROM subscriptions s JOIN users u ON u.id = s.user_id JOIN plans p ON p.id = s.plan_id
             WHERE u.reseller_id = ? AND s.status = 'active'";
        $params = [$reseller_id];
        if ($exclude_user_id > 0) {
            $sql .= ' AND u.id <> ?';
            $params[] = $exclude_user_id;
        }
        $row = $this->app->db->one($sql, $params);
        return [
            'max_domains' => (int) ($row['max_domains'] ?? 0), 'max_mailboxes' => (int) ($row['max_mailboxes'] ?? 0),
            'max_databases' => (int) ($row['max_databases'] ?? 0), 'disk_bytes' => (int) ($row['disk_bytes'] ?? 0),
        ];
    }

    /** Reseller ne smije prodati više nego što mu paket dopušta. Bez paketa = bez gatea. */
    protected function assertResellerCapacity(int $reseller_id, int $new_plan_id, int $exclude_user_id = 0): void
    {
        $ceiling = $this->resellerCeiling($reseller_id);
        if ($ceiling === null) {
            return;
        }
        $alloc = $this->resellerAllocated($reseller_id, $exclude_user_id);
        $new = $this->app->db->one(
            'SELECT max_domains, max_mailboxes, max_databases, disk_bytes FROM plans WHERE id = ?',
            [$new_plan_id]
        ) ?? throw new HttpException(422, 'invalid_plan');
        foreach (['max_domains', 'max_mailboxes', 'max_databases', 'disk_bytes'] as $k) {
            if ((int) $alloc[$k] + (int) $new[$k] > (int) $ceiling[$k]) {
                throw new HttpException(422, 'reseller_quota_exceeded');
            }
        }
    }
}
