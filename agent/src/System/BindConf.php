<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * BIND9 zone: zona se piše u temp file, validira named-checkzone PRIJE
 * aktivacije, zones.conf se regenerira iz direktorija, named-checkconf + reload.
 */
final class BindConf
{
    public const ZONE_DIR = '/etc/bind/forgepanel';
    public const ZONES_CONF = '/etc/bind/forgepanel.zones.conf';
    public const NAMED_LOCAL = '/etc/bind/named.conf.local';

    public const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR', 'TLSA'];

    /** @param list<array{name: string, type: string, content: string, ttl: int, prio: ?int}> $records */
    public static function writeZone(
        string $domain,
        array $records,
        int $serial,
        string $ns1,
        string $ns2,
    ): void {
        $domain = Validator::fqdn($domain);
        $zone_text = self::zoneText($domain, $records, $serial, $ns1, $ns2);

        if (!is_dir(self::ZONE_DIR)) {
            mkdir(self::ZONE_DIR, 0o755, true);
        }
        $zone_file = self::ZONE_DIR . "/db.{$domain}";
        $tmp = $zone_file . '.new';
        file_put_contents($tmp, $zone_text);

        $check = Proc::run(['named-checkzone', $domain, $tmp]);
        if (!$check->ok()) {
            unlink($tmp);
            throw new ValidationException('named-checkzone pao: ' . trim($check->stdout ?: $check->stderr));
        }
        rename($tmp, $zone_file);

        self::regenerateConf();
        self::reload($domain);
    }

    public static function deleteZone(string $domain): void
    {
        $domain = Validator::fqdn($domain);
        @unlink(self::ZONE_DIR . "/db.{$domain}");
        self::regenerateConf();
        self::reload(null);
    }

    /** @param list<array{name: string, type: string, content: string, ttl: int, prio: ?int}> $records */
    public static function zoneText(string $domain, array $records, int $serial, string $ns1, string $ns2): string
    {
        $lines = [
            '$TTL 3600',
            "@ IN SOA {$ns1}. hostmaster.{$domain}. (",
            "    {$serial} ; serial",
            '    3600   ; refresh',
            '    900    ; retry',
            '    1209600 ; expire',
            '    300 )  ; negative ttl',
            "@ IN NS {$ns1}.",
            "@ IN NS {$ns2}.",
        ];
        foreach ($records as $record) {
            $lines[] = self::recordLine($domain, $record);
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param array{name: string, type: string, content: string, ttl: int, prio: ?int} $record */
    private static function recordLine(string $domain, array $record): string
    {
        $type = strtoupper((string) $record['type']);
        if (!in_array($type, self::RECORD_TYPES, true)) {
            throw new ValidationException("Nepodržan tip zapisa: $type");
        }
        $name = (string) $record['name'];
        if ($name !== '@' && !preg_match('/^[a-z0-9_*]([a-z0-9_.*-]{0,62})?$/i', $name)) {
            throw new ValidationException("Neispravno ime zapisa: $name");
        }
        $ttl = max(60, min(604800, (int) $record['ttl']));
        $content = trim((string) $record['content']);
        if ($content === '' || preg_match('/[\r\n]/', $content)) {
            throw new ValidationException('Sadržaj zapisa ne smije biti prazan ni višelinijski');
        }

        $rdata = match ($type) {
            'A' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ?: throw new ValidationException("A zapis nije ispravna IPv4 adresa: $content"),
            'AAAA' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ?: throw new ValidationException("AAAA zapis nije ispravna IPv6 adresa: $content"),
            'CNAME', 'NS', 'PTR' => Validator::fqdn(rtrim($content, '.'), $type) . '.',
            'MX' => ((int) ($record['prio'] ?? 10)) . ' ' . Validator::fqdn(rtrim($content, '.'), 'MX') . '.',
            'TXT' => self::txtRdata($content),
            'SRV', 'CAA', 'TLSA' => self::genericRdata($content, $type),
        };

        return "{$name} {$ttl} IN {$type} {$rdata}";
    }

    private static function txtRdata(string $content): string
    {
        if (strlen($content) > 1024) {
            throw new ValidationException('TXT zapis predug (max 1024)');
        }
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], trim($content, '"'));
        // TXT stringovi > 255 znakova se dijele po RFC-u
        $chunks = str_split($escaped, 255);
        return implode(' ', array_map(static fn (string $c) => "\"$c\"", $chunks));
    }

    private static function genericRdata(string $content, string $type): string
    {
        if (!preg_match('/^[a-zA-Z0-9 ._"-]{1,255}$/', $content)) {
            throw new ValidationException("$type sadržaj ima nedozvoljene znakove");
        }
        return $content;
    }

    /** zones.conf se regenerira iz postojećih db.* fileova — jedno mjesto istine. */
    private static function regenerateConf(): void
    {
        $clauses = [];
        foreach (glob(self::ZONE_DIR . '/db.*') ?: [] as $zone_file) {
            $domain = substr(basename($zone_file), 3);
            if (!preg_match('/^[a-z0-9.-]+$/', $domain)) {
                continue;
            }
            $clauses[] = "zone \"{$domain}\" {\n    type master;\n    file \"{$zone_file}\";\n};";
        }
        file_put_contents(self::ZONES_CONF, implode("\n\n", $clauses) . "\n");

        $include_line = 'include "' . self::ZONES_CONF . '";';
        $named_local = is_file(self::NAMED_LOCAL) ? (string) file_get_contents(self::NAMED_LOCAL) : '';
        if (!str_contains($named_local, $include_line)) {
            file_put_contents(self::NAMED_LOCAL, $named_local . "\n" . $include_line . "\n");
        }

        $check = Proc::run(['named-checkconf']);
        if (!$check->ok()) {
            throw new \RuntimeException('named-checkconf pao: ' . trim($check->stdout ?: $check->stderr));
        }
    }

    private static function reload(?string $zone): void
    {
        if (!Systemd::isActive('named')) {
            return; // config je validan; named će ga pokupiti na startu
        }
        if ($zone !== null && Proc::run(['rndc', 'reload', $zone])->ok()) {
            return;
        }
        Proc::run(['rndc', 'reload']) ->ok() || Systemd::reload('named');
    }
}
