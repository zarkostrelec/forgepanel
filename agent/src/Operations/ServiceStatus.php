<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

final class ServiceStatus extends Operation
{
    /** Whitelist servisa kojima panel smije upravljati. */
    public const SERVICES = [
        'nginx', 'apache2', 'mariadb', 'mysql', 'postfix', 'dovecot', 'rspamd',
        'named', 'proftpd', 'fail2ban', 'redis-server', 'docker', 'clamav-daemon',
        'forge-agentd',
        'php8.1-fpm', 'php8.2-fpm', 'php8.3-fpm', 'php8.4-fpm', 'php8.5-fpm',
    ];

    public function validate(array $params): void
    {
        Validator::oneOf($params['service'] ?? null, self::SERVICES, 'service');
    }

    public function execute(array $params, TaskContext $context): array
    {
        return Systemd::show((string) $params['service']);
    }
}
