<?php

declare(strict_types=1);

namespace ForgePanel\Agent;

use ForgePanel\Agent\Operations;
use ForgePanel\Agent\Operations\Operation;

/**
 * Whitelist operacija. Nepoznat op-code se odbija — agent NIKAD ne izvršava
 * raw komande. Svaka operacija je zasebna klasa s vlastitom validacijom.
 */
final class OperationRegistry
{
    /** @var array<string, class-string<Operation>> */
    private const OPERATIONS = [
        'vhost.create'      => Operations\VhostCreate::class,
        'vhost.delete'      => Operations\VhostDelete::class,
        'vhost.php_set'     => Operations\VhostPhpSet::class,
        'vhost.suspend'     => Operations\VhostSuspend::class,
        'db.create'         => Operations\DbCreate::class,
        'db.delete'         => Operations\DbDelete::class,
        'db.user_create'    => Operations\DbUserCreate::class,
        'fs.list'           => Operations\FsList::class,
        'fs.read'           => Operations\FsRead::class,
        'fs.write'          => Operations\FsWrite::class,
        'fs.mkdir'          => Operations\FsMkdir::class,
        'fs.delete'         => Operations\FsDelete::class,
        'fs.chmod'          => Operations\FsChmod::class,
        'cron.sync'         => Operations\CronSync::class,
        'service.status'    => Operations\ServiceStatus::class,
        'service.reload'    => Operations\ServiceReload::class,
        'ssl.issue'         => Operations\SslIssue::class,
        'system.metrics'    => Operations\SystemMetrics::class,
    ];

    /** @var array<string, Operation> */
    private array $instances = [];

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
    ) {
    }

    public function resolve(string $op_code): ?Operation
    {
        $class = self::OPERATIONS[$op_code] ?? null;
        if ($class === null) {
            return null;
        }
        return $this->instances[$op_code] ??= new $class($this->config, $this->db);
    }
}
