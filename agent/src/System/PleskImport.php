<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * Plesk backup parser — čita backup XML (backup_info.xml / *.xml unutar dumpa)
 * i mapira na ForgePanel entitete (poglavlje 11). Format varira po Plesk verziji
 * pa parsiramo defenzivno: domene, baze, DB useri i mail korisnici po XPath-u.
 */
final class PleskImport
{
    /**
     * @return array{username: ?string, main_domain: ?string, domains: list<string>, databases: list<string>, db_users: list<string>, email_accounts: list<string>, has_homedir: bool}
     */
    public static function parse(string $extracted_dir): array
    {
        $xml_file = self::findXml($extracted_dir);
        if ($xml_file === null) {
            throw new \RuntimeException('Nije Plesk backup struktura (nema XML dumpa s <domain>)');
        }

        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_file($xml_file);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new \RuntimeException('Neispravan Plesk backup XML');
        }

        $domains = [];
        $databases = [];
        $db_users = [];
        $email_accounts = [];

        // Domene: <domain name="..."> i <subdomain name="...">
        foreach (self::xpathAll($doc, ['//domain', '//Domain', '//subdomain']) as $node) {
            $name = self::attr($node, 'name');
            $clean = $name === null ? null : self::sanitizeDomain($name);
            if ($clean !== null) {
                $domains[] = $clean;
                // Mail korisnici unutar domene
                foreach ($node->xpath('.//mailuser | .//MailUser | .//mailname') ?: [] as $mu) {
                    $local = self::attr($mu, 'name');
                    if ($local !== null && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/i', $local)) {
                        $email_accounts[] = strtolower($local) . '@' . $clean;
                    }
                }
            }
        }

        // Baze: <database name="..."> + <dbuser name="..."> / <database-user>
        foreach (self::xpathAll($doc, ['//database', '//Database']) as $db) {
            $name = self::attr($db, 'name');
            if ($name !== null) {
                $databases[] = self::sanitizeIdent($name);
            }
            foreach ($db->xpath('.//dbuser | .//DbUser | .//database-user | .//user') ?: [] as $u) {
                $uname = self::attr($u, 'name');
                if ($uname !== null) {
                    $db_users[] = self::sanitizeIdent($uname);
                }
            }
        }

        $main = $domains[0] ?? null;
        return [
            'username' => null,
            'main_domain' => $main,
            'domains' => array_values(array_unique(array_filter($domains))),
            'databases' => array_values(array_unique(array_filter($databases))),
            'db_users' => array_values(array_unique(array_filter($db_users))),
            'email_accounts' => array_values(array_unique($email_accounts)),
            'has_homedir' => is_dir("$extracted_dir") && glob("$extracted_dir/*/*") !== [],
        ];
    }

    /** Pronađi XML dump koji sadrži <domain>. */
    private static function findXml(string $dir): ?string
    {
        $candidates = array_merge(
            glob("$dir/*.xml") ?: [],
            glob("$dir/*/*.xml") ?: [],
            glob("$dir/backup_info*.xml") ?: []
        );
        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            $head = (string) file_get_contents($file, false, null, 0, 65536);
            if (str_contains($head, '<domain') || str_contains($head, '<Domain') || str_contains($head, 'migration-dump')) {
                return $file;
            }
        }
        return null;
    }

    /**
     * @param list<string> $paths
     * @return list<\SimpleXMLElement>
     */
    private static function xpathAll(\SimpleXMLElement $doc, array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            foreach ($doc->xpath($p) ?: [] as $node) {
                $out[] = $node;
            }
        }
        return $out;
    }

    private static function attr(\SimpleXMLElement $node, string $name): ?string
    {
        $val = (string) ($node[$name] ?? '');
        return $val === '' ? null : $val;
    }

    private static function sanitizeDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) ? $domain : null;
    }

    private static function sanitizeIdent(string $ident): string
    {
        return preg_replace('/[^a-z0-9_]/i', '_', trim($ident)) ?? $ident;
    }
}
