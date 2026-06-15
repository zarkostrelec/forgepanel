<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Notifier;
use ForgePanel\Agent\TaskContext;

/** alarm.test — pošalje testnu poruku na sve konfigurirane alarm kanale. */
final class AlarmTest extends Operation
{
    public function validate(array $params): void
    {
        // bez parametara — koristi spremljenu monitoring_alarms konfiguraciju
    }

    public function execute(array $params, TaskContext $context): array
    {
        $cfg = Notifier::config($this->db);
        $channels = is_array($cfg['channels'] ?? null) ? array_keys(array_filter($cfg['channels'])) : [];
        Notifier::dispatch(
            $this->db,
            'info',
            'ForgePanel test alarm',
            'Ovo je testna poruka iz ForgePanela. Ako je vidiš, kanal radi.'
        );
        return ['enabled' => ($cfg['enabled'] ?? false) === true, 'channels' => $channels];
    }
}
