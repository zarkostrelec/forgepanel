<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** service.reload — config test PRIJE reloada gdje servis to podržava. */
final class ServiceReload extends Operation
{
    private const CONFIG_TESTS = [
        'nginx' => ['nginx', '-t'],
        'apache2' => ['apachectl', 'configtest'],
        'php8.1-fpm' => ['php-fpm8.1', '-t'],
        'php8.2-fpm' => ['php-fpm8.2', '-t'],
        'php8.3-fpm' => ['php-fpm8.3', '-t'],
        'php8.4-fpm' => ['php-fpm8.4', '-t'],
        'php8.5-fpm' => ['php-fpm8.5', '-t'],
        'named' => ['named-checkconf'],
    ];

    public function validate(array $params): void
    {
        Validator::oneOf($params['service'] ?? null, ServiceStatus::SERVICES, 'service');
        Validator::oneOf($params['action'] ?? 'reload', ['reload', 'restart'], 'action');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $service = (string) $params['service'];
        $test = self::CONFIG_TESTS[$service] ?? null;
        if ($test !== null) {
            Proc::mustRun($test);
        }
        ($params['action'] ?? 'reload') === 'restart' ? Systemd::restart($service) : Systemd::reload($service);
        return ['service' => $service, 'active' => Systemd::isActive($service)];
    }
}
