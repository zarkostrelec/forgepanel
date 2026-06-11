<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * backup.vhost_create — fileovi (tar.gz) + baze (mariadb-dump --single-transaction)
 * + manifest.json sa SHA-256 checksumovima. Format dokumentiran u docs/backup-format.md,
 * restore izvediv i ručno bez panela. Izvršava se s nice/ionice da ne ubije produkciju.
 */
final class BackupVhostCreate extends Operation
{
    public const BACKUP_ROOT = '/var/backups/forgepanel';
    public const FORMAT_VERSION = 1;

    private const NICE = ['nice', '-n', '19', 'ionice', '-c', '3'];

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['backup_id'] ?? null, 'backup_id');
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        if (!is_array($params['databases'] ?? [])) {
            throw new ValidationException('databases mora biti lista');
        }
        foreach ($params['databases'] ?? [] as $database) {
            Validator::identifier($database, 'database');
        }
        $keep = $params['keep'] ?? 7;
        if (!is_int($keep) || $keep < 1 || $keep > 365) {
            throw new ValidationException('keep mora biti 1–365');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $backup_id = (int) $params['backup_id'];
        $domain = Validator::fqdn($params['domain']);
        $databases = $params['databases'] ?? [];
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;
        if (!is_dir($vhost_root)) {
            throw new ValidationException("Vhost direktorij ne postoji: $vhost_root");
        }

        $backup_dir = self::BACKUP_ROOT . '/' . $domain . '/' . date('Ymd-His');
        mkdir($backup_dir, 0o700, true);

        try {
            $artifacts = [];

            $context->output("Arhiviram fileove ($vhost_root)");
            $context->progress(10);
            Proc::mustRun(
                [...self::NICE, 'tar', '-czf', "$backup_dir/files.tar.gz", '-C', $vhost_root, '.'],
                timeout_s: 3600,
            );
            $artifacts[] = 'files.tar.gz';
            $context->progress(50);

            foreach ($databases as $i => $database) {
                $database = Validator::identifier($database, 'database');
                $context->output("Dump baze $database (--single-transaction)");
                $sql_path = "$backup_dir/db_{$database}.sql";
                Proc::mustRun(
                    [...self::NICE, 'mariadb-dump', '--single-transaction', '--routines', '--triggers',
                        "--result-file=$sql_path", $database],
                    timeout_s: 3600,
                );
                Proc::mustRun([...self::NICE, 'gzip', '-f', $sql_path], timeout_s: 3600);
                $artifacts[] = "db_{$database}.sql.gz";
                $context->progress(50 + (int) (30 * ($i + 1) / max(1, count($databases))));
            }

            $context->output('Manifest + SHA-256 checksumovi');
            $manifest = $this->writeManifest($backup_dir, $domain, $artifacts, $databases);
            $size_bytes = array_sum(array_column($manifest['files'], 'size_bytes'));

            $this->db->run(
                "UPDATE backups SET status = 'done', path = ?, size_bytes = ?, manifest = ? WHERE id = ?",
                [$backup_dir, $size_bytes, json_encode($manifest, JSON_UNESCAPED_SLASHES), $backup_id]
            );

            $context->output('Retencija: čuvam zadnjih ' . ($params['keep'] ?? 7));
            $this->applyRetention($domain, (int) ($params['keep'] ?? 7));
            $context->progress(100);

            return ['path' => $backup_dir, 'size_bytes' => $size_bytes];
        } catch (\Throwable $e) {
            $this->db->run("UPDATE backups SET status = 'failed' WHERE id = ?", [$backup_id]);
            Proc::run(['rm', '-rf', '--one-file-system', $backup_dir]);
            throw $e;
        }
    }

    /**
     * @param list<string> $artifacts
     * @param list<string> $databases
     * @return array{format_version: int, domain: string, created_at: string, databases: list<string>, files: list<array{name: string, sha256: string, size_bytes: int}>}
     */
    private function writeManifest(string $backup_dir, string $domain, array $artifacts, array $databases): array
    {
        $files = [];
        foreach ($artifacts as $name) {
            $path = "$backup_dir/$name";
            $files[] = [
                'name' => $name,
                'sha256' => (string) hash_file('sha256', $path),
                'size_bytes' => (int) filesize($path),
            ];
        }
        $manifest = [
            'format_version' => self::FORMAT_VERSION,
            'domain' => $domain,
            'created_at' => date('c'),
            'databases' => array_values($databases),
            'files' => $files,
        ];
        file_put_contents(
            "$backup_dir/manifest.json",
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        return $manifest;
    }

    private function applyRetention(string $domain, int $keep): void
    {
        $dirs = glob(self::BACKUP_ROOT . '/' . $domain . '/[0-9]*') ?: [];
        sort($dirs);
        foreach (array_slice($dirs, 0, max(0, count($dirs) - $keep)) as $old_dir) {
            Proc::run(['rm', '-rf', '--one-file-system', $old_dir]);
            $this->db->run("DELETE FROM backups WHERE path = ? AND status = 'done'", [$old_dir]);
        }
    }
}
