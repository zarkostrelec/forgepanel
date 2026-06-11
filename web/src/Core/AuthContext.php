<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Kontekst prijavljenog korisnika. Izolacija multi-tenanta se provodi OVDJE,
 * u middleware sloju — nikad ručno po endpointima.
 */
final class AuthContext
{
    /** @param list<string> $scopes @param list<int> $subscription_ids */
    public function __construct(
        public readonly int $user_id,
        public readonly string $email,
        public readonly string $role,
        public readonly array $scopes,
        public readonly array $subscription_ids,
        private readonly Db $db,
    ) {
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function requireRole(string ...$roles): void
    {
        if (!in_array($this->role, $roles, true)) {
            throw new HttpException(403, 'forbidden_role');
        }
    }

    public function requireScope(string $scope): void
    {
        if (in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true)) {
            return;
        }
        throw new HttpException(403, 'missing_scope: ' . $scope);
    }

    /** Klijent fizički ne može dohvatiti tuđi resurs — provjera vlasništva po subscription_id. */
    public function requireSubscription(int $subscription_id): void
    {
        if ($this->isAdmin() || in_array($subscription_id, $this->subscription_ids, true)) {
            return;
        }
        throw new HttpException(404, 'not_found');
    }

    /**
     * @return array<string, mixed> vhost ili 404 (tuđi resurs se ne razlikuje od nepostojećeg)
     * @param ?string $permission ako je zadan, delegirani pristup mora pokrivati tu permisiju
     */
    public function vhostOr404(int $vhost_id, ?string $permission = null): array
    {
        $vhost = $this->db->one('SELECT * FROM vhosts WHERE id = ?', [$vhost_id]);
        if ($vhost === null) {
            throw new HttpException(404, 'not_found');
        }
        if ($this->isAdmin() || in_array((int) $vhost['subscription_id'], $this->subscription_ids, true)) {
            return $vhost;
        }
        // Delegirani pristup: grantee vidi vhost samo ako ima delegaciju s tom permisijom
        if ($this->hasDelegation($vhost_id, $permission)) {
            return $vhost;
        }
        throw new HttpException(404, 'not_found');
    }

    private function hasDelegation(int $vhost_id, ?string $permission): bool
    {
        $row = $this->db->one(
            'SELECT permissions FROM delegated_access WHERE grantee_user_id = ? AND vhost_id = ?',
            [$this->user_id, $vhost_id]
        );
        if ($row === null) {
            return false;
        }
        // Delegat MORA imati eksplicitnu permisiju. Operacije bez navedene
        // permisije (php_set, backend, delete, ssl) su isključivo za vlasnika.
        if ($permission === null) {
            return false;
        }
        $perms = json_decode((string) $row['permissions'], true);
        return is_array($perms) && in_array($permission, $perms, true);
    }

    /** Vhostovi do kojih korisnik ima delegirani pristup (za listanje). @return list<int> */
    public function delegatedVhostIds(): array
    {
        return array_map(intval(...), array_column(
            $this->db->all('SELECT vhost_id FROM delegated_access WHERE grantee_user_id = ?', [$this->user_id]),
            'vhost_id'
        ));
    }
}
