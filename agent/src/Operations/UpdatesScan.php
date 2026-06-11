<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Apt;
use ForgePanel\Agent\System\Components;
use ForgePanel\Agent\TaskContext;

/**
 * updates.scan — apt-get update + parsiranje upgradable paketa → puni
 * components tablicu (verzije, security flag) i osigurava default politike.
 */
final class UpdatesScan extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
    }

    public function execute(array $params, TaskContext $context): array
    {
        $context->output('apt-get update');
        Apt::update();
        $context->progress(40);

        $upgradable = Apt::listUpgradable();
        $context->output(count($upgradable) . ' paketa ima dostupan update');

        $scanned = 0;
        foreach (array_keys(Components::CATALOG) as $component) {
            $current = Components::currentVersion($component);
            if ($current === null) {
                continue; // komponenta nije instalirana
            }

            $available = null;
            $security = false;
            foreach (Components::CATALOG[$component]['packages'] as $package) {
                if (isset($upgradable[$package])) {
                    $available ??= $upgradable[$package]['available'];
                    $security = $security || str_contains($upgradable[$package]['suite'] ?? '', 'security');
                }
            }

            $this->db->run(
                "INSERT INTO components (name, current_version, available_version, security_update, status, packages)
                 VALUES (?, ?, ?, ?, 'installed', ?)
                 ON DUPLICATE KEY UPDATE current_version = VALUES(current_version),
                     available_version = VALUES(available_version),
                     security_update = VALUES(security_update),
                     status = IF(status = 'frozen', 'frozen', 'installed')",
                [$component, $current, $available, (int) $security,
                    json_encode(Components::CATALOG[$component]['packages'])]
            );

            // Default politika: manual, prozor ned 03–05, bez auto-primjene
            $row = $this->db->one('SELECT id FROM components WHERE name = ?', [$component]);
            $this->db->run(
                "INSERT INTO update_policies (component_id, mode, window_days)
                 SELECT ?, 'manual', '[7]'
                 WHERE NOT EXISTS (SELECT 1 FROM update_policies WHERE component_id = ?)",
                [$row['id'], $row['id']]
            );
            $scanned++;
        }

        $context->progress(100);
        $context->output("Skenirano komponenti: $scanned");
        return ['components' => $scanned, 'upgradable_packages' => count($upgradable)];
    }
}
