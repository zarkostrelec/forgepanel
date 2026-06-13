<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Apt;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\System\Ufw;
use ForgePanel\Agent\TaskContext;

/**
 * dns.install — naknadna instalacija BIND9 iz panela (DNS je opcionalna komponenta).
 * Isto kao installer (optional_components → dns): paketi, /etc/bind/forgepanel,
 * named enable, ufw 53, components zastavica.
 */
final class DnsInstall extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        // bez parametara
    }

    public function execute(array $params, TaskContext $context): array
    {
        $context->output("Instaliram BIND9 (bind9, bind9utils)\n");
        $context->progress(20);
        Apt::install(['bind9', 'bind9utils'], $context->output(...));

        if (!is_dir('/etc/bind/forgepanel')) {
            mkdir('/etc/bind/forgepanel', 0o755, true);
        }

        $context->output("Pokrećem named + ufw 53\n");
        $context->progress(75);
        Systemd::enableNow('named');
        try {
            Ufw::allowPort(53, 'tcp');
            Ufw::allowPort(53, 'udp');
        } catch (\Throwable) {
            // ufw u containeru zna failati — nije kritično
        }

        $this->db->run(
            "INSERT INTO components (name, status, packages) VALUES ('bind9', 'installed', '[\"bind9\"]')
             ON DUPLICATE KEY UPDATE status = 'installed'"
        );
        $context->progress(100);
        $context->output("DNS (BIND9) spreman.\n");
        return ['installed' => true];
    }
}
