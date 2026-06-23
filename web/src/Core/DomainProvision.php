<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Auto-provisioning DNS-a pri kreiranju domene (vhost.create):
 *  - lokalna BIND zona sa standardnim zapisima, ILI
 *  - zapis istih zapisa u odabranu Cloudflare zonu + veza vhost ↔ CF račun.
 *
 * Sve je best-effort na razini pojedinog zapisa: greška na CF-u/BIND-u NE smije
 * srušiti kreiranje vhosta (DNS se uvijek može dovršiti ručno na DNS/CF ekranu).
 */
final class DomainProvision
{
    /**
     * Kreira lokalnu BIND zonu sa standardnim zapisima (A/www/mail/MX/SPF/DMARC/CAA).
     * Preskače ako BIND nije instaliran (DNS je opcionalna komponenta) ili zona već postoji.
     *
     * @return ?int  id kreirane zone, ili null ako je preskočeno
     */
    public static function localZone(App $app, string $domain, int $subscription_id): ?int
    {
        $installed = $app->db->one("SELECT 1 FROM components WHERE name = 'bind9' AND status = 'installed'") !== null;
        if (!$installed) {
            return null;
        }
        if ($app->db->one('SELECT 1 FROM dns_zones WHERE domain = ?', [$domain]) !== null) {
            return null;
        }

        $app->db->run(
            'INSERT INTO dns_zones (domain, subscription_id, serial) VALUES (?, ?, ?)',
            [$domain, $subscription_id, time()]
        );
        $zone_id = $app->db->lastId();

        $mail = $app->db->one('SELECT dkim_selector, dkim_txt FROM mail_domains WHERE domain = ?', [$domain]);
        foreach (DnsDefaults::records($domain, self::serverIp($app), $mail) as $r) {
            $app->db->run(
                'INSERT INTO dns_records (zone_id, name, type, content, ttl, prio) VALUES (?, ?, ?, ?, ?, ?)',
                [$zone_id, $r['name'], $r['type'], $r['content'], $r['ttl'], $r['prio']]
            );
        }

        try {
            DnsSync::sync($app, $zone_id);
        } catch (\Throwable $e) {
            // named-checkzone odbio zonu — vrati DB u prethodno stanje (kao DnsController::createZone)
            $app->db->run('DELETE FROM dns_zones WHERE id = ?', [$zone_id]);
            throw $e;
        }
        return $zone_id;
    }

    /**
     * Zapisuje standardni set zapisa u CF zonu koja po imenu odgovara domeni na danom
     * računu, te poveže vhost ↔ CF zona (cloudflare_zones, dns_mode = cloudflare).
     * Zona MORA već postojati na CF računu — token nema ovlast kreiranja zone.
     *
     * @param array{id: int, api_token: string} $account  red iz cloudflare_accounts
     * @return array{ok: bool, zone_id?: string, created?: list<string>, error?: string}
     */
    public static function cloudflare(App $app, int $vhost_id, string $domain, array $account, bool $proxy): array
    {
        $cf = new CloudflareClient((new Crypto($app->config))->decrypt((string) $account['api_token']));

        $zone_id = null;
        foreach ($cf->zones() as $z) {
            if (strtolower($z['name']) === strtolower($domain)) {
                $zone_id = $z['id'];
                break;
            }
        }
        if ($zone_id === null) {
            return ['ok' => false, 'error' => 'cf_zone_not_found'];
        }

        $mail = $app->db->one('SELECT dkim_selector, dkim_txt FROM mail_domains WHERE domain = ?', [$domain]);
        $created = [];
        foreach (DnsDefaults::records($domain, self::serverIp($app), $mail) as $r) {
            $name = $r['name'] === '@' ? $domain : $r['name'] . '.' . $domain;
            try {
                $cf->createRecord($zone_id, $r['type'], $name, $r['content'], $r['proxy'] && $proxy, $r['ttl'], $r['prio']);
                $created[] = $r['type'] . ' ' . $name;
            } catch (\Throwable $e) {
                $created[] = $r['type'] . ' ' . $name . ': ' . $e->getMessage();
            }
        }

        // Novi vhost → nema prethodne veze; plain INSERT (cloudflare_zones nema UNIQUE na vhost_id).
        $app->db->run(
            "INSERT INTO cloudflare_zones (vhost_id, account_id, zone_id, dns_mode, proxy_default)
             VALUES (?, ?, ?, 'cloudflare', ?)",
            [$vhost_id, $account['id'], $zone_id, (int) $proxy]
        );
        return ['ok' => true, 'zone_id' => $zone_id, 'created' => $created];
    }

    /** Javna IPv4 servera (settings.server_ipv4, JSON-enkodirana); fallback 127.0.0.1. */
    private static function serverIp(App $app): string
    {
        $row = $app->db->one('SELECT value FROM settings WHERE `key` = ?', ['server_ipv4']);
        $ip = $row === null ? null : json_decode((string) $row['value'], true);
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '127.0.0.1';
    }
}
