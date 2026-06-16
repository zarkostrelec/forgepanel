<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/**
 * service.reload / service.action — upravljanje servisima (start/stop/restart/reload).
 * Config test PRIJE reload/restart gdje servis to podržava. Whitelist iz ServiceStatus.
 */
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
        Validator::oneOf($params['action'] ?? 'reload', ['reload', 'restart', 'start', 'stop'], 'action');
        // Agent ne smije ugasiti sam sebe — tada panel više ne bi mogao pokrenuti servis natrag.
        if (($params['service'] ?? '') === 'forge-agentd' && ($params['action'] ?? '') === 'stop') {
            throw new ValidationException('forge-agentd se ne može zaustaviti iz panela (zaključalo bi upravljanje)');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $service = (string) $params['service'];
        $action = (string) ($params['action'] ?? 'reload');

        // config test samo pri reload/restart (start/stop ne učitavaju config kroz panel)
        if (in_array($action, ['reload', 'restart'], true) && isset(self::CONFIG_TESTS[$service])) {
            Proc::mustRun(self::CONFIG_TESTS[$service]);
        }

        match ($action) {
            'start' => Systemd::start($service),
            'stop' => Systemd::stop($service),
            'restart' => Systemd::restart($service),
            default => Systemd::reload($service),
        };

        return ['service' => $service, 'action' => $action, 'active' => Systemd::isActive($service)];
    }
}
