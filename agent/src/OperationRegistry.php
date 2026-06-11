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
        'vhost.backend_set' => Operations\VhostBackendSet::class,
        'dns.zone_write'    => Operations\DnsZoneWrite::class,
        'dns.zone_delete'   => Operations\DnsZoneDelete::class,
        'ftp.sync'          => Operations\FtpSync::class,
        'mail.setup'        => Operations\MailSetup::class,
        'mail.domain_add'   => Operations\MailDomainAdd::class,
        'mail.domain_delete' => Operations\MailDomainDelete::class,
        'backup.vhost_create' => Operations\BackupVhostCreate::class,
        'backup.restore'    => Operations\BackupRestore::class,
        'backup.delete'     => Operations\BackupDelete::class,
        'git.keygen'        => Operations\GitKeygen::class,
        'git.deploy'        => Operations\GitDeploy::class,
        'updates.scan'      => Operations\UpdatesScan::class,
        'updates.apply'     => Operations\UpdatesApply::class,
        'docker.create'     => Operations\DockerCreate::class,
        'docker.action'     => Operations\DockerAction::class,
        'docker.proxy_map'  => Operations\DockerProxyMap::class,
        'malware.scan'      => Operations\MalwareScan::class,
        'quarantine.action' => Operations\QuarantineRestore::class,
        'firewall.action'   => Operations\FirewallAction::class,
        'config.history'    => Operations\ConfigHistory::class,
        'migrator.cpanel_parse' => Operations\MigratorCpanelParse::class,
        'waf.toggle'        => Operations\WafToggle::class,
        'country.block'     => Operations\CountryBlock::class,
        'deliverability.check' => Operations\DeliverabilityCheck::class,
        'staging.clone'     => Operations\StagingClone::class,
        'apps.wp_install'   => Operations\WpInstall::class,
        'apps.wp_checksums' => Operations\WpChecksums::class,
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
