<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\ValidationException;

/**
 * Config time-machine — interni git repo nad /etc pathovima koje panel kontrolira.
 * Svaka promjena configa se commita; diff, povijest i one-click restore.
 * Webmin i Plesk nemaju ništa slično.
 */
final class ConfigGit
{
    public const REPO = '/var/lib/forgepanel/config-history';

    /** Pathovi koje panel verzionira (prošireni iz Components katalog config_dirs). */
    public const TRACKED = [
        '/etc/nginx', '/etc/apache2', '/etc/php', '/etc/postfix', '/etc/dovecot',
        '/etc/rspamd', '/etc/bind', '/etc/proftpd', '/etc/fail2ban', '/etc/forgepanel',
    ];

    public static function init(): void
    {
        if (is_dir(self::REPO . '/.git')) {
            return;
        }
        if (!is_dir(self::REPO)) {
            mkdir(self::REPO, 0o700, true);
        }
        self::git(['init', '-q']);
        self::git(['config', 'user.email', 'agent@forgepanel']);
        self::git(['config', 'user.name', 'ForgePanel']);
        self::git(['config', 'commit.gpgsign', 'false']);
    }

    /**
     * Snapshota trenutno stanje tracked pathova u repo i commita.
     * @return ?string commit hash, ili null ako nema promjena
     */
    public static function snapshot(string $changed_by, string $message): ?string
    {
        self::init();
        foreach (self::TRACKED as $src) {
            if (!is_dir($src)) {
                continue;
            }
            $dest = self::REPO . $src;
            if (!is_dir($dest)) {
                mkdir($dest, 0o700, true);
            }
            // rsync uz brisanje obrisanih fileova; bez socket/special fileova
            Proc::run(['rsync', '-a', '--delete', '--exclude', '*.sock', '--exclude', '*.pid',
                $src . '/', $dest . '/']);
        }
        self::git(['add', '-A']);
        if (Proc::run(['git', '-C', self::REPO, 'diff', '--cached', '--quiet'])->ok()) {
            return null; // nema promjena
        }
        self::git(['commit', '-q', '-m', "$message\n\nchanged-by: $changed_by", '--author', "$changed_by <$changed_by@forgepanel>"]);
        return trim(self::git(['rev-parse', 'HEAD'])->stdout);
    }

    /** @return list<array{hash: string, date: string, message: string, changed_by: string}> */
    public static function history(int $limit = 100): array
    {
        if (!is_dir(self::REPO . '/.git')) {
            return [];
        }
        $out = self::git(['log', "-{$limit}", '--pretty=format:%H%x1f%cI%x1f%s%x1f%an'])->stdout;
        $history = [];
        foreach (array_filter(explode("\n", $out)) as $line) {
            [$hash, $date, $message, $by] = array_pad(explode("\x1f", $line), 4, '');
            $history[] = ['hash' => $hash, 'date' => $date, 'message' => $message, 'changed_by' => $by];
        }
        return $history;
    }

    public static function diff(string $hash): string
    {
        self::assertHash($hash);
        return self::git(['show', '--stat', '--patch', $hash])->stdout;
    }

    /**
     * Vraća tracked pathove na stanje iz commita (restore).
     * Prije restorea snapshota trenutno stanje (rollback rollbacka je moguć).
     */
    public static function restore(string $hash, string $changed_by): void
    {
        self::assertHash($hash);
        self::snapshot($changed_by, 'pre-restore auto-snapshot');

        foreach (self::TRACKED as $path) {
            $in_repo = self::REPO . $path;
            // Postoji li path u tom commitu?
            $rel = ltrim($path, '/');
            if (!Proc::run(['git', '-C', self::REPO, 'cat-file', '-e', "$hash:$rel"])->ok()) {
                continue;
            }
            // Izvuci verziju iz commita u radni dir repoa pa rsync nazad u /etc
            self::git(['checkout', $hash, '--', $rel]);
            if (is_dir($in_repo)) {
                Proc::mustRun(['rsync', '-a', '--delete', '--exclude', '*.sock', '--exclude', '*.pid',
                    $in_repo . '/', $path . '/']);
            }
        }
        // Vrati radni dir repoa na HEAD
        self::git(['checkout', 'HEAD', '--', '.']);
    }

    private static function assertHash(string $hash): void
    {
        if (!preg_match('/^[0-9a-f]{7,40}$/', $hash)) {
            throw new ValidationException('Neispravan commit hash');
        }
    }

    /** @param list<string> $args */
    private static function git(array $args): Proc
    {
        return Proc::mustRun(['git', '-C', self::REPO, ...$args], timeout_s: 120);
    }
}
