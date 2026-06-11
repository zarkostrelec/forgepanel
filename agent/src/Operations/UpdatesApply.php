<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Apt;
use ForgePanel\Agent\System\Components;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * updates.apply — update komponente s punim sigurnosnim ciklusom:
 * pre-update config snapshot + zapis verzija → apt --only-upgrade →
 * post-update health check → automatski rollback na FAIL (downgrade +
 * restore configa + komponenta frozen + alarm adminima).
 */
final class UpdatesApply extends Operation
{
    public const SNAPSHOT_DIR = '/var/backups/forgepanel-configs';

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        $component = $params['component'] ?? null;
        if (!is_string($component) || !isset(Components::CATALOG[$component])) {
            throw new ValidationException('Nepoznata komponenta');
        }
        if (isset($params['simulate_health_fail']) && !is_bool($params['simulate_health_fail'])) {
            throw new ValidationException('simulate_health_fail mora biti bool');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $component = (string) $params['component'];
        $def = Components::CATALOG[$component];

        $row = $this->db->one('SELECT * FROM components WHERE name = ?', [$component])
            ?? throw new ValidationException('Komponenta nije u registru — pokreni updates.scan');
        if ($row['status'] === 'frozen') {
            throw new ValidationException('Komponenta je frozen — odmrzni je prije updatea');
        }

        $packages = Components::installedPackages($component);
        if ($packages === []) {
            throw new ValidationException('Komponenta nije instalirana');
        }

        // 1. Zapis trenutnih verzija (za rollback)
        $old_versions = [];
        foreach ($packages as $package) {
            $old_versions[$package] = Apt::installedVersion($package);
        }
        $was_active = $def['service'] !== null && Systemd::isActive($def['service']);

        // 2. Pre-update snapshot configa
        $snapshot_path = $this->snapshotConfigs($component, $def['config_dirs'], $context);
        $context->progress(20);

        $rollback_info = [
            'versions' => $old_versions,
            'config_snapshot' => $snapshot_path,
            'was_active' => $was_active,
        ];
        $this->db->run(
            "INSERT INTO component_updates (component_id, from_version, to_version, status, rollback_info)
             VALUES (?, ?, ?, 'running', ?)",
            [$row['id'], $row['current_version'] ?? '?', $row['available_version'] ?? '?', json_encode($rollback_info)]
        );
        $update_id = $this->db->lastId();
        $this->db->run("UPDATE components SET status = 'updating' WHERE id = ?", [$row['id']]);

        try {
            // 3. Update (apt cache se NE čisti — stari .deb ostaje za rollback)
            $context->output('apt-get install --only-upgrade ' . implode(' ', $packages));
            Apt::upgradeOnly($packages, $context->output(...));
            $context->progress(60);

            // 4. Post-update health check
            $context->output('Health check…');
            $health_error = ($params['simulate_health_fail'] ?? false)
                ? 'SIMULIRAN health fail (test)'
                : $this->healthCheck($component, $def, $was_active);

            if ($health_error !== null) {
                throw new \RuntimeException("Health check FAIL: $health_error");
            }

            $new_version = Components::currentVersion($component);
            $this->db->run(
                "UPDATE components SET current_version = ?, available_version = NULL, security_update = 0, status = 'installed' WHERE id = ?",
                [$new_version, $row['id']]
            );
            $this->db->run("UPDATE component_updates SET status = 'done', to_version = ? WHERE id = ?", [$new_version, $update_id]);
            $context->progress(100);
            $context->output("Update OK: $component → $new_version");
            return ['component' => $component, 'version' => $new_version];
        } catch (\Throwable $e) {
            $context->output('GREŠKA: ' . $e->getMessage());
            $context->output('Pokrećem automatski rollback…');
            $this->rollback($component, $def, $old_versions, $snapshot_path, $was_active, $context);

            $this->db->run("UPDATE components SET status = 'frozen' WHERE id = ?", [$row['id']]);
            $this->db->run("UPDATE update_policies SET mode = 'frozen' WHERE component_id = ?", [$row['id']]);
            $this->db->run("UPDATE component_updates SET status = 'rolled_back' WHERE id = ?", [$update_id]);
            $this->notifyAdmins(
                "Update komponente $component NEUSPJEŠAN — izvršen rollback",
                $e->getMessage() . ' — komponenta je zamrznuta (frozen) do ručne provjere.'
            );
            throw $e;
        }
    }

    /** @param list<string> $config_dirs */
    private function snapshotConfigs(string $component, array $config_dirs, TaskContext $context): ?string
    {
        $existing = array_values(array_filter($config_dirs, is_dir(...)));
        if ($existing === []) {
            return null;
        }
        $dir = self::SNAPSHOT_DIR . "/$component";
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $snapshot_path = "$dir/" . date('Ymd-His') . '.tar.gz';
        $context->output('Config snapshot: ' . implode(' ', $existing));
        // -C / + relativni pathovi → restore je tar -xzf -C /
        Proc::mustRun(['tar', '-czf', $snapshot_path, '-C', '/',
            ...array_map(static fn (string $d) => ltrim($d, '/'), $existing)], timeout_s: 600);
        return $snapshot_path;
    }

    /** @param array{service: ?string, config_test: ?list<string>} $def */
    private function healthCheck(string $component, array $def, bool $was_active): ?string
    {
        if ($def['config_test'] !== null) {
            $test = Proc::run($def['config_test']);
            if (!$test->ok()) {
                return 'config test pao: ' . trim($test->stderr ?: $test->stdout);
            }
        }
        if ($def['service'] !== null && $was_active && !Systemd::isActive($def['service'])) {
            // pokušaj restart pa ponovno provjeri (apt katkad ostavi servis ugašen)
            Proc::run(['systemctl', 'restart', $def['service']]);
            sleep(2);
            if (!Systemd::isActive($def['service'])) {
                return "servis {$def['service']} nije aktivan nakon updatea";
            }
        }
        if ($component === 'mariadb') {
            try {
                $this->db->run('SELECT 1');
            } catch (\Throwable $e) {
                return 'baza ne odgovara: ' . $e->getMessage();
            }
        }
        return null;
    }

    /** @param array<string, ?string> $old_versions */
    private function rollback(
        string $component,
        array $def,
        array $old_versions,
        ?string $snapshot_path,
        bool $was_active,
        TaskContext $context,
    ): void {
        // 1. Downgrade paketa na prethodne verzije (radi iz apt cachea ili repoa)
        $specs = [];
        foreach ($old_versions as $package => $version) {
            if ($version !== null) {
                $specs[] = "$package=$version";
            }
        }
        if ($specs !== []) {
            $context->output('Downgrade: ' . implode(' ', $specs));
            $down = Proc::run(
                ['env', 'DEBIAN_FRONTEND=noninteractive', 'apt-get', 'install', '-y', '-q',
                    '--allow-downgrades', ...$specs],
                timeout_s: 1800,
            );
            $context->output($down->ok() ? 'Downgrade OK' : 'Downgrade NIJE uspio (stara verzija više nije dostupna) — vraćam samo config');
        }

        // 2. Restore config snapshota
        if ($snapshot_path !== null && is_file($snapshot_path)) {
            $context->output("Restore configa iz $snapshot_path");
            Proc::run(['tar', '-xzf', $snapshot_path, '-C', '/'], timeout_s: 600);
        }

        // 3. Restart servisa
        if ($def['service'] !== null && $was_active) {
            Proc::run(['systemctl', 'restart', $def['service']]);
        }
    }

    private function notifyAdmins(string $title, string $body): void
    {
        $admins = $this->db->all(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'admin'"
        );
        foreach ($admins as $admin) {
            $this->db->run(
                "INSERT INTO notifications (user_id, severity, title, body) VALUES (?, 'error', ?, ?)",
                [$admin['id'], $title, $body]
            );
        }
    }
}
