<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * cPanel cpmove/full backup parser. Industrijski standard formata (tar) —
 * naš import ga MORA čitati (poglavlje 0/11). Mapira na ForgePanel entitete.
 */
final class CpanelImport
{
    /**
     * Parsira raspakirani cpmove direktorij u ForgePanel entitete (dry-run friendly).
     * @return array{username: ?string, main_domain: ?string, domains: list<string>, databases: list<string>, db_users: list<string>, email_accounts: list<string>, has_homedir: bool}
     */
    public static function parse(string $extracted_dir): array
    {
        $result = [
            'username' => null, 'main_domain' => null, 'domains' => [],
            'databases' => [], 'db_users' => [], 'email_accounts' => [], 'has_homedir' => false,
        ];

        // cpmove se raspakira u cpmove-<user>/ ili direktno; nađi korijen
        $root = self::findRoot($extracted_dir);
        if ($root === null) {
            throw new \RuntimeException('Nije cPanel cpmove struktura (nedostaje meta/ ili userdata/)');
        }

        // username + main domain iz meta/homedir_paths ili userdata/main
        $main = "$root/userdata/main";
        if (is_file($main)) {
            $yaml = (string) file_get_contents($main);
            if (preg_match('/main_domain:\s*(\S+)/', $yaml, $m)) {
                $result['main_domain'] = self::sanitizeDomain($m[1]);
            }
            // addon/parked/sub domene
            foreach (['addon_domains', 'parked_domains', 'sub_domains'] as $section) {
                if (preg_match('/' . $section . ':\s*\n((?:\s+\S.*\n?)*)/', $yaml, $sm)) {
                    foreach (self::yamlKeys($sm[1]) as $domain) {
                        $clean = self::sanitizeDomain($domain);
                        if ($clean !== null) {
                            $result['domains'][] = $clean;
                        }
                    }
                }
            }
        }

        // username
        foreach (glob("$root/cp/*") ?: [] as $cp_file) {
            $result['username'] = basename($cp_file);
            break;
        }

        // baze: mysql.sql ili mysql/*.sql
        foreach (glob("$root/mysql/*.create") ?: [] as $create) {
            $result['databases'][] = self::sanitizeIdent(basename($create, '.create'));
        }
        if ($result['databases'] === [] && is_file("$root/mysql.sql")) {
            if (preg_match_all('/CREATE DATABASE[^`]*`([^`]+)`/i', (string) file_get_contents("$root/mysql.sql"), $dm)) {
                $result['databases'] = array_map(self::sanitizeIdent(...), $dm[1]);
            }
        }

        // DB useri
        if (is_file("$root/mysql.sql")) {
            if (preg_match_all("/CREATE USER\\s+'([^']+)'/i", (string) file_get_contents("$root/mysql.sql"), $um)) {
                $result['db_users'] = array_values(array_unique(array_map(self::sanitizeIdent(...), $um[1])));
            }
        }

        // email accounts: homedir/mail/<domain>/<user> ili etc/<domain>/passwd
        foreach (glob("$root/homedir/mail/*/*", GLOB_ONLYDIR) ?: [] as $mailbox) {
            $domain = basename(dirname($mailbox));
            $result['email_accounts'][] = basename($mailbox) . '@' . self::sanitizeDomain($domain);
        }

        $result['has_homedir'] = is_dir("$root/homedir");
        $result['domains'] = array_values(array_unique(array_filter([
            $result['main_domain'],
            ...$result['domains'],
        ])));

        return $result;
    }

    private static function findRoot(string $dir): ?string
    {
        if (is_dir("$dir/userdata") || is_dir("$dir/meta")) {
            return $dir;
        }
        foreach (glob("$dir/cpmove-*", GLOB_ONLYDIR) ?: [] as $sub) {
            if (is_dir("$sub/userdata") || is_dir("$sub/meta")) {
                return $sub;
            }
        }
        // jedan poddirektorij?
        $subs = glob("$dir/*", GLOB_ONLYDIR) ?: [];
        if (count($subs) === 1 && (is_dir("{$subs[0]}/userdata") || is_dir("{$subs[0]}/meta"))) {
            return $subs[0];
        }
        return null;
    }

    /** @return list<string> */
    private static function yamlKeys(string $block): array
    {
        $keys = [];
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^\s+([a-z0-9._-]+):/i', $line, $m)) {
                $keys[] = $m[1];
            }
        }
        return $keys;
    }

    private static function sanitizeDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) ? $domain : null;
    }

    private static function sanitizeIdent(string $ident): string
    {
        return preg_replace('/[^a-z0-9_]/i', '_', $ident) ?? $ident;
    }
}
