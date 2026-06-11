<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * backup.restore — granularni restore: samo fileovi ili samo baza.
 * Checksumovi iz manifesta se verificiraju PRIJE restorea.
 */
final class BackupRestore extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        Validator::oneOf($params['mode'] ?? null, ['files', 'db'], 'mode');
        self::backupPath($params['path'] ?? null);
        if (($params['mode'] ?? '') === 'db') {
            Validator::identifier($params['db_name'] ?? null, 'db_name');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        $backup_dir = self::backupPath($params['path']);
        $mode = (string) $params['mode'];
        $sys_user = 'vh_' . (int) $params['vhost_id'];

        $manifest = $this->verifyManifest($backup_dir, $context);

        if ($mode === 'files') {
            $vhost_root = Validator::VHOST_ROOT . '/' . $domain;
            if (!is_dir($vhost_root)) {
                throw new ValidationException("Vhost direktorij ne postoji: $vhost_root");
            }
            $context->output("Vraćam fileove u $vhost_root");
            $context->progress(40);
            Proc::mustRun(['tar', '-xzf', "$backup_dir/files.tar.gz", '-C', $vhost_root], timeout_s: 3600);
            Proc::mustRun(['chown', '-R', "$sys_user:$sys_user", $vhost_root]);
        } else {
            $db_name = Validator::identifier($params['db_name'], 'db_name');
            if (!in_array($db_name, $manifest['databases'] ?? [], true)) {
                throw new ValidationException("Baza $db_name nije u ovom backupu");
            }
            $context->output("Vraćam bazu $db_name");
            $context->progress(40);
            Proc::mustRun(['gunzip', '-kf', "$backup_dir/db_{$db_name}.sql.gz"], timeout_s: 3600);
            Proc::mustRun(['mariadb', $db_name, '-e', "source $backup_dir/db_{$db_name}.sql"], timeout_s: 3600);
            unlink("$backup_dir/db_{$db_name}.sql");
        }

        $context->progress(100);
        $context->output('Restore završen.');
        return ['restored' => $mode, 'from' => $backup_dir];
    }

    /** @return array<string, mixed> */
    private function verifyManifest(string $backup_dir, TaskContext $context): array
    {
        $manifest_path = "$backup_dir/manifest.json";
        if (!is_file($manifest_path)) {
            throw new ValidationException('manifest.json ne postoji — backup nije u ForgePanel formatu');
        }
        $manifest = json_decode((string) file_get_contents($manifest_path), true);
        if (!is_array($manifest)) {
            throw new ValidationException('manifest.json nije čitljiv');
        }
        $context->output('Verificiram SHA-256 checksumove');
        foreach ($manifest['files'] ?? [] as $file) {
            $path = $backup_dir . '/' . basename((string) $file['name']);
            if (!is_file($path) || !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $path))) {
                throw new ValidationException('Checksum ne odgovara: ' . $file['name'] . ' — backup je oštećen');
            }
        }
        return $manifest;
    }

    public static function backupPath(mixed $value): string
    {
        if (!is_string($value) || str_contains($value, "\0")) {
            throw new ValidationException('path nije ispravan');
        }
        $real = realpath($value);
        if ($real === false || !str_starts_with($real, BackupVhostCreate::BACKUP_ROOT . '/')) {
            throw new ValidationException('path mora biti unutar ' . BackupVhostCreate::BACKUP_ROOT);
        }
        return $real;
    }
}
