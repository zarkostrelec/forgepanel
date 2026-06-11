<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * cron.sync — sinkronizira SVE cron jobove vhosta u /etc/cron.d/forgepanel-vh_<id>.
 * Jobovi se izvršavaju kao vhost user (NIKAD root), output ide u logs/cron.log.
 */
final class CronSync extends Operation
{
    private const SCHEDULE_FIELD_RE = '/^[0-9*,\/-]+$/';

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        if (!is_array($params['jobs'] ?? null)) {
            throw new ValidationException('jobs mora biti lista');
        }
        foreach ($params['jobs'] as $job) {
            self::assertSchedule($job['schedule'] ?? null);
            self::assertCommand($job['command'] ?? null);
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);
        $sys_user = 'vh_' . $vhost_id;
        $cron_path = "/etc/cron.d/forgepanel-{$sys_user}";
        $log_path = Validator::VHOST_ROOT . "/$domain/logs/cron.log";

        if ($params['jobs'] === []) {
            @unlink($cron_path);
            return ['synced' => 0];
        }

        $lines = [
            "# ForgePanel cron — vhost $domain (generirano, ne uređivati ručno)",
            'SHELL=/bin/sh',
            'PATH=/usr/local/bin:/usr/bin:/bin',
        ];
        foreach ($params['jobs'] as $job) {
            $schedule = self::assertSchedule($job['schedule']);
            $command = self::assertCommand($job['command']);
            $lines[] = "$schedule $sys_user $command >> $log_path 2>&1";
        }

        file_put_contents($cron_path, implode("\n", $lines) . "\n");
        chmod($cron_path, 0o644);

        return ['synced' => count($params['jobs'])];
    }

    public static function assertSchedule(mixed $schedule): string
    {
        if (!is_string($schedule)) {
            throw new ValidationException('schedule mora biti string');
        }
        $fields = preg_split('/\s+/', trim($schedule));
        if (!is_array($fields) || count($fields) !== 5) {
            throw new ValidationException('schedule mora imati 5 polja (min sat dan mjesec dan_u_tjednu)');
        }
        foreach ($fields as $field) {
            if (!preg_match(self::SCHEDULE_FIELD_RE, $field)) {
                throw new ValidationException("Neispravno cron polje: $field");
            }
        }
        return implode(' ', $fields);
    }

    public static function assertCommand(mixed $command): string
    {
        if (!is_string($command) || trim($command) === '' || strlen($command) > 500) {
            throw new ValidationException('command je obavezan (max 500 znakova)');
        }
        // % je u cronu newline; kontrolni znakovi razbijaju format fajla
        if (preg_match('/[%\x00-\x1f\x7f]/', $command)) {
            throw new ValidationException('command ne smije sadržavati % ni kontrolne znakove');
        }
        return trim($command);
    }
}
