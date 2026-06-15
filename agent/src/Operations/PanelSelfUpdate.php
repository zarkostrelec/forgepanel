<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;

/**
 * panel.self_update — primjena potpisanog panel release-a (mothership push).
 * Agent NEOVISNO verificira ed25519 potpis manifesta + SHA-256 paketa PRIJE bilo
 * kakvog deploya; radi backup trenutne verzije, pa atomski zamijeni web/ + agent/.
 * Bez verifikacije bi ovo bio supply-chain backdoor na svaki server.
 */
final class PanelSelfUpdate extends Operation
{
    private const TARGET = '/opt/forgepanel';

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        if (!is_array($params['manifest'] ?? null)) {
            throw new ValidationException('manifest nedostaje');
        }
        if (!is_string($params['signature'] ?? null) || !is_string($params['pubkey'] ?? null)) {
            throw new ValidationException('signature/pubkey nedostaje');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $m = $params['manifest'];
        $version = (string) ($m['version'] ?? '');

        // 1) potpis (neovisno o web sloju)
        $ok = sodium_crypto_sign_verify_detached(
            base64_decode((string) $params['signature']),
            self::canonical($m),
            base64_decode((string) $params['pubkey'])
        );
        if (!$ok) {
            throw new \RuntimeException('potpis nevažeći — update odbijen');
        }
        $context->output("Potpis OK (v{$version})\n");
        $context->progress(10);

        // 2) download (isključivo https)
        $url = (string) ($m['package_url'] ?? '');
        if (!preg_match('#^https://#', $url)) {
            throw new \RuntimeException('package_url mora biti https');
        }
        $tar = '/tmp/forgepanel-update-' . bin2hex(random_bytes(4)) . '.tar.gz';
        Proc::mustRun(['curl', '-fsSL', '--proto', '=https', '--tlsv1.2', '-o', $tar, '--', $url], timeout_s: 600);
        $context->output("Paket preuzet\n");
        $context->progress(40);

        // 3) checksum
        if (!hash_equals(strtolower((string) ($m['sha256'] ?? '')), strtolower((string) hash_file('sha256', $tar)))) {
            @unlink($tar);
            throw new \RuntimeException('SHA-256 ne odgovara — paket neispravan');
        }
        $context->output("Checksum OK\n");
        $context->progress(55);

        // 4) raspakiraj u staging i provjeri strukturu
        $stage = '/tmp/forgepanel-stage-' . preg_replace('/[^A-Za-z0-9._-]/', '', $version);
        Proc::run(['rm', '-rf', $stage]);
        mkdir($stage, 0o755, true);
        Proc::mustRun(['tar', '-xzf', $tar, '-C', $stage]);
        $root = is_dir("$stage/web") ? $stage : (($g = glob("$stage/*/web")) ? \dirname($g[0]) : $stage);
        if (!is_dir("$root/web") || !is_dir("$root/agent")) {
            throw new \RuntimeException('paket nema web/ i agent/ — neispravan release');
        }
        $context->output("Raspakirano\n");
        $context->progress(70);

        // 5) backup trenutne verzije (reverzibilno)
        $backup = '/opt/forgepanel-backup-' . date('Ymd-His');
        Proc::mustRun(['rsync', '-a', '--delete', self::TARGET . '/web/', "$backup/web/"]);
        Proc::mustRun(['rsync', '-a', '--delete', self::TARGET . '/agent/', "$backup/agent/"]);
        $context->output("Backup: $backup\n");
        $context->progress(82);

        // 6) primijeni web + agent (+ modules ako postoje)
        Proc::mustRun(['rsync', '-a', "$root/web/", self::TARGET . '/web/']);
        Proc::mustRun(['rsync', '-a', "$root/agent/", self::TARGET . '/agent/']);
        if (is_dir("$root/modules")) {
            Proc::mustRun(['rsync', '-a', "$root/modules/", self::TARGET . '/modules/']);
        }
        @chmod(self::TARGET . '/agent/bin/forge-agentd', 0o755);
        $this->db->run(
            "INSERT INTO settings (`key`, value) VALUES ('panel_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [json_encode($version)]
        );
        $context->output("Primijenjeno\n");
        $context->progress(92);

        // 7) reload panel FPM + restart agenta s odgodom (da ovaj task stigne završiti)
        Systemd::reload('php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm');
        Proc::run(['systemd-run', '--on-active=4', 'systemctl', 'restart', 'forge-agentd']);
        Proc::run(['rm', '-rf', $tar, $stage]);

        $context->progress(100);
        $context->output("Update na v{$version} primijenjen. Agent se restarta za par sekundi.\n");
        return ['version' => $version, 'backup' => $backup];
    }

    /** @param array<string,mixed> $m */
    private static function canonical(array $m): string
    {
        $out = [];
        foreach (['version', 'channel', 'package_url', 'sha256', 'min_version', 'published_at'] as $k) {
            $out[$k] = (string) ($m[$k] ?? '');
        }
        return (string) json_encode($out, JSON_UNESCAPED_SLASHES);
    }
}
