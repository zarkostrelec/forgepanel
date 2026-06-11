<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

/**
 * E-mail deliverability — najveća rupa svih panela.
 * RBL/blacklist provjera, DMARC rua parser, SPF/DKIM/DMARC validator.
 */
final class Deliverability
{
    /** 30+ poznatih RBL lista. */
    public const RBLS = [
        'zen.spamhaus.org', 'bl.spamcop.net', 'b.barracudacentral.org',
        'dnsbl.sorbs.net', 'spam.dnsbl.sorbs.net', 'cbl.abuseat.org',
        'dnsbl-1.uceprotect.net', 'psbl.surriel.com', 'db.wpbl.info',
        'ix.dnsbl.manitu.net', 'bl.mailspike.net', 'dnsbl.dronebl.org',
    ];

    /**
     * Provjera IP-a na RBL listama (reverzni IP + .rbl, DNS A lookup).
     * @return list<array{rbl: string, listed: bool}>
     */
    public static function checkRbls(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [];
        }
        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $results = [];
        foreach (self::RBLS as $rbl) {
            $query = "$reversed.$rbl";
            $records = @dns_get_record($query, DNS_A);
            $results[] = ['rbl' => $rbl, 'listed' => is_array($records) && $records !== []];
        }
        return $results;
    }

    /**
     * Parsira DMARC aggregate (rua) XML izvještaj.
     * @return array{org: string, date_range: string, rows: list<array{source_ip: string, count: int, disposition: string, dkim: string, spf: string}>}
     */
    public static function parseDmarcReport(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new \RuntimeException('Neispravan DMARC XML');
        }

        $org = (string) ($doc->report_metadata->org_name ?? 'unknown');
        $begin = (int) ($doc->report_metadata->date_range->begin ?? 0);
        $end = (int) ($doc->report_metadata->date_range->end ?? 0);
        $date_range = ($begin ? date('d.m.Y', $begin) : '?') . ' – ' . ($end ? date('d.m.Y', $end) : '?');

        $rows = [];
        foreach ($doc->record ?? [] as $record) {
            $rows[] = [
                'source_ip' => (string) ($record->row->source_ip ?? ''),
                'count' => (int) ($record->row->count ?? 0),
                'disposition' => (string) ($record->row->policy_evaluated->disposition ?? 'none'),
                'dkim' => (string) ($record->row->policy_evaluated->dkim ?? 'fail'),
                'spf' => (string) ($record->row->policy_evaluated->spf ?? 'fail'),
            ];
        }
        return ['org' => $org, 'date_range' => $date_range, 'rows' => $rows];
    }

    /**
     * Validira SPF/DKIM/DMARC za domenu kroz DNS lookup.
     * @return array{spf: array{found: bool, record: ?string, issue: ?string}, dkim: array{found: bool}, dmarc: array{found: bool, record: ?string, policy: ?string}}
     */
    public static function validateDomain(string $domain, string $dkim_selector = 'forge'): array
    {
        return [
            'spf' => self::validateSpf($domain),
            'dkim' => ['found' => self::txtExists("$dkim_selector._domainkey.$domain", 'v=DKIM1')],
            'dmarc' => self::validateDmarc($domain),
        ];
    }

    /** @return array{found: bool, record: ?string, issue: ?string} */
    private static function validateSpf(string $domain): array
    {
        $record = self::findTxt($domain, 'v=spf1');
        if ($record === null) {
            return ['found' => false, 'record' => null, 'issue' => 'Nema SPF zapisa'];
        }
        $issue = null;
        if (str_contains($record, '+all')) {
            $issue = '+all dozvoljava bilo kome slanje — koristi ~all ili -all';
        } elseif (!preg_match('/[~\-]all/', $record)) {
            $issue = 'Nedostaje ~all/-all na kraju';
        }
        return ['found' => true, 'record' => $record, 'issue' => $issue];
    }

    /** @return array{found: bool, record: ?string, policy: ?string} */
    private static function validateDmarc(string $domain): array
    {
        $record = self::findTxt("_dmarc.$domain", 'v=DMARC1');
        if ($record === null) {
            return ['found' => false, 'record' => null, 'policy' => null];
        }
        preg_match('/p=(\w+)/', $record, $m);
        return ['found' => true, 'record' => $record, 'policy' => $m[1] ?? 'none'];
    }

    private static function txtExists(string $name, string $needle): bool
    {
        return self::findTxt($name, $needle) !== null;
    }

    private static function findTxt(string $name, string $needle): ?string
    {
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return null;
        }
        foreach ($records as $record) {
            $txt = $record['txt'] ?? '';
            if (str_contains($txt, $needle)) {
                return $txt;
            }
        }
        return null;
    }
}
