<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\Db;

/**
 * DMARC rua ingest — izvještaji (aggregate reports) dolaze na panel mailbox
 * (npr. dmarc@domena), parsiraju se i prikazuju kao graf "tko šalje u ime domene".
 * Ni jedan panel ovo nema ugrađeno; svi šalju korisnika na vanjske servise.
 *
 * Izvještaji su priloženi kao .xml, .xml.gz ili .zip — ovdje ih raspakiramo i
 * izvučemo sirovi XML. Maildir poruke se skeniraju iz {new,cur} i premještaju
 * (rename) u .Processed kako se ne bi obrađivale dvaput.
 */
final class DmarcIngest
{
    /**
     * Iz sirove e-mail poruke izvuče sve DMARC XML izvještaje (raspakirane).
     * Podržava plain XML body, base64 priloge i .gz/.zip kompresiju.
     * @return list<string> XML stringovi
     */
    public static function extractReports(string $raw): array
    {
        // Razdvoji headere od tijela
        $split = preg_split("/\r?\n\r?\n/", $raw, 2);
        $headers = $split[0] ?? '';
        $body = $split[1] ?? '';

        $reports = [];

        // Multipart? Pronađi boundary i obradi svaki dio.
        if (preg_match('/boundary="?([^"\r\n;]+)"?/i', $headers, $bm)) {
            $boundary = $bm[1];
            $parts = explode('--' . $boundary, $body);
            foreach ($parts as $part) {
                $report = self::decodePart($part);
                if ($report !== null) {
                    $reports[] = $report;
                }
            }
        } else {
            // Jednodijelna poruka — cijelo tijelo je možda izvještaj
            $report = self::decodePart($raw);
            if ($report !== null) {
                $reports[] = $report;
            }
        }

        // Fallback: gola XML poruka bez MIME omotača
        if ($reports === [] && str_contains($body, '<feedback')) {
            $reports[] = $body;
        }
        return $reports;
    }

    /** Dekodira jedan MIME dio (ili cijelu poruku) u XML ili null ako nije izvještaj. */
    private static function decodePart(string $part): ?string
    {
        $split = preg_split("/\r?\n\r?\n/", $part, 2);
        $head = strtolower($split[0] ?? '');
        $content = $split[1] ?? '';

        // Samo prilozi/dijelovi relevantnih tipova
        $is_relevant = str_contains($head, 'application/zip')
            || str_contains($head, 'application/gzip')
            || str_contains($head, 'application/x-gzip')
            || str_contains($head, 'application/octet-stream')
            || str_contains($head, 'text/xml')
            || str_contains($head, 'application/xml')
            || preg_match('/filename="?[^"\r\n]+\.(xml|gz|zip)/i', $head);
        if (!$is_relevant) {
            return null;
        }

        $bytes = str_contains($head, 'base64')
            ? (string) base64_decode(preg_replace('/\s+/', '', $content) ?? '', true)
            : quoted_printable_decode($content);

        $name = '';
        if (preg_match('/filename="?([^"\r\n;]+)/i', $head, $fm)) {
            $name = strtolower($fm[1]);
        }
        return self::inflate($bytes, $name);
    }

    /** Raspakira bajtove (gz/zip) ili vrati XML; null ako nije DMARC feedback. */
    private static function inflate(string $bytes, string $name): ?string
    {
        $xml = null;
        if (str_ends_with($name, '.gz') || (strlen($bytes) > 2 && substr($bytes, 0, 2) === "\x1f\x8b")) {
            $xml = @gzdecode($bytes) ?: null;
        } elseif (str_ends_with($name, '.zip') || (strlen($bytes) > 2 && substr($bytes, 0, 2) === 'PK')) {
            $xml = self::unzipFirst($bytes);
        } elseif (str_contains($bytes, '<feedback')) {
            $xml = $bytes;
        }
        return ($xml !== null && str_contains($xml, '<feedback')) ? $xml : null;
    }

    /** Izvuče prvi unos iz ZIP arhive (preko privremene datoteke + ZipArchive). */
    private static function unzipFirst(string $bytes): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dmarc');
        if ($tmp === false) {
            return null;
        }
        try {
            file_put_contents($tmp, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                return null;
            }
            $xml = $zip->getNumFiles() > 0 ? ($zip->getFromIndex(0) ?: null) : null;
            $zip->close();
            return $xml === false ? null : $xml;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Skenira mailbox(e) i ingestira nove izvještaje u dmarc_reports.
     * @return array{scanned: int, ingested: int}
     */
    public static function run(Db $db, string $vmail_root = '/var/vmail', string $mailbox = 'dmarc'): array
    {
        $scanned = 0;
        $ingested = 0;
        $domains = $db->all('SELECT id, domain FROM mail_domains');
        foreach ($domains as $d) {
            $maildir = "$vmail_root/{$d['domain']}/$mailbox/Maildir";
            $processed = "$maildir/.Processed/cur";
            foreach (['new', 'cur'] as $sub) {
                $dir = "$maildir/$sub";
                if (!is_dir($dir)) {
                    continue;
                }
                foreach (glob("$dir/*") ?: [] as $file) {
                    if (!is_file($file)) {
                        continue;
                    }
                    $scanned++;
                    $raw = (string) @file_get_contents($file);
                    foreach (self::extractReports($raw) as $xml) {
                        if (self::store($db, (int) $d['id'], $xml)) {
                            $ingested++;
                        }
                    }
                    // Premjesti obrađenu poruku da se ne ponavlja
                    if (!is_dir($processed)) {
                        @mkdir($processed, 0o700, true);
                    }
                    @rename($file, "$processed/" . basename($file));
                }
            }
        }
        return ['scanned' => $scanned, 'ingested' => $ingested];
    }

    /** Parsira XML i sprema; dedup po (mail_domain_id, org, date_range). True ako je novo. */
    private static function store(Db $db, int $mail_domain_id, string $xml): bool
    {
        try {
            $report = Deliverability::parseDmarcReport($xml);
        } catch (\Throwable) {
            return false;
        }
        $exists = $db->one(
            'SELECT 1 FROM dmarc_reports WHERE mail_domain_id = ? AND org = ? AND date_range = ?',
            [$mail_domain_id, $report['org'], $report['date_range']]
        );
        if ($exists !== null) {
            return false;
        }
        $db->run(
            'INSERT INTO dmarc_reports (mail_domain_id, org, date_range, parsed) VALUES (?, ?, ?, ?)',
            [$mail_domain_id, $report['org'], $report['date_range'], json_encode($report, JSON_UNESCAPED_SLASHES)]
        );
        return true;
    }
}
