<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * Filesystem operacije unutar vhost roota. Svaki path prolazi realpath
 * provjeru (Validator::vhostPath) — symlink bijeg iz vhosta je nemoguć.
 */
final class Fs
{
    public static function listDir(string $path): array
    {
        Validator::vhostPath($path);
        if (!is_dir($path)) {
            throw new ValidationException("Nije direktorij: $path");
        }
        $entries = [];
        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $entries[] = [
                'name' => $item->getFilename(),
                'type' => $item->isDir() ? 'dir' : ($item->isLink() ? 'link' : 'file'),
                'size_bytes' => $item->isFile() ? $item->getSize() : 0,
                'mode' => substr(sprintf('%o', $item->getPerms()), -4),
                'owner' => posix_getpwuid($item->getOwner())['name'] ?? (string) $item->getOwner(),
                'mtime' => date('c', $item->getMTime()),
            ];
        }
        usort($entries, static fn (array $a, array $b) => [$a['type'] !== 'dir', $a['name']] <=> [$b['type'] !== 'dir', $b['name']]);
        return $entries;
    }

    public static function read(string $path, int $max_bytes = 5_242_880): string
    {
        Validator::vhostPath($path);
        if (!is_file($path)) {
            throw new ValidationException("Nije file: $path");
        }
        if (filesize($path) > $max_bytes) {
            throw new ValidationException('File prevelik za editor');
        }
        return (string) file_get_contents($path);
    }

    public static function write(string $path, string $content, string $owner): void
    {
        Validator::vhostPath($path);
        Validator::identifier($owner, 'owner');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new ValidationException("Direktorij ne postoji: $dir");
        }
        // Atomski write: temp file + rename, pa vlasništvo vhost usera (nikad root fileovi u vhostu)
        $tmp = $dir . '/.fp-' . bin2hex(random_bytes(8));
        file_put_contents($tmp, $content);
        chown($tmp, $owner);
        chgrp($tmp, $owner);
        chmod($tmp, is_file($path) ? (fileperms($path) & 0o777) : 0o644);
        rename($tmp, $path);
    }

    public static function mkdir(string $path, string $owner): void
    {
        Validator::vhostPath($path);
        Validator::identifier($owner, 'owner');
        if (!mkdir($path, 0o755, true) && !is_dir($path)) {
            throw new \RuntimeException("mkdir nije uspio: $path");
        }
        chown($path, $owner);
        chgrp($path, $owner);
    }

    public static function delete(string $path): void
    {
        $real = realpath($path);
        if ($real === false) {
            throw new ValidationException("Ne postoji: $path");
        }
        Validator::vhostPath($real);
        // Zaštita: ne brisati sam vhost root niti direktni docroot vhosta kroz fs.delete
        if (substr_count(trim(str_replace(Validator::VHOST_ROOT, '', $real), '/'), '/') < 1) {
            throw new ValidationException('Brisanje vhost roota ide kroz vhost.delete');
        }
        is_dir($real) && !is_link($real) ? self::rrmdir($real) : unlink($real);
    }

    public static function chmod(string $path, int $mode): void
    {
        Validator::vhostPath($path);
        if ($mode < 0 || $mode > 0o777) {
            throw new ValidationException('Neispravan mode');
        }
        chmod($path, $mode);
    }

    /**
     * Disk project quota (ext4/xfs) za vhost dir: projid (= vhost_id) + hard limit u
     * bajtovima — enforcement disk kvote plana PO VHOSTU bez CloudLinuxa. Best-effort:
     * vraća false ako fs nije montiran s prjquota ili alati nedostaju (NE baca — disk
     * kvota ne smije srušiti kreiranje vhosta). Validacija puta: unutar VHOST_ROOT.
     */
    public static function setProjectQuota(string $path, int $bytes, int $projid): bool
    {
        $real = realpath($path);
        if ($real === false || $projid <= 0 || $bytes <= 0) {
            return false;
        }
        if ($real !== Validator::VHOST_ROOT && !str_starts_with($real, Validator::VHOST_ROOT . '/')) {
            return false;
        }
        $fstype = trim(Proc::run(['findmnt', '-no', 'FSTYPE', '--target', $real])->stdout);
        $mount = trim(Proc::run(['findmnt', '-no', 'TARGET', '--target', $real])->stdout);
        if ($mount === '') {
            return false;
        }
        if ($fstype === 'xfs') {
            $set = Proc::run(['xfs_quota', '-x', '-c', "project -s -p {$real} {$projid}", $mount]);
            $lim = Proc::run(['xfs_quota', '-x', '-c', "limit -p bhard={$bytes} {$projid}", $mount]);
            return $set->ok() && $lim->ok();
        }
        // ext4 i ostali quota-tools fs: projid + inherit flag (+P), pa hard limit u 1K blokovima
        $chattr = Proc::run(['chattr', '-R', '-p', (string) $projid, '+P', $real]);
        $blocks_kb = (int) ceil($bytes / 1024);
        $setq = Proc::run(['setquota', '-P', (string) $projid, '0', (string) $blocks_kb, '0', '0', $mount]);
        return $chattr->ok() && $setq->ok();
    }

    private static function rrmdir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
