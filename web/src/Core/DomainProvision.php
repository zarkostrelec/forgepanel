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

        // Idempotentno (repair smije ponoviti): zamijeni postojeću vezu za ovaj vhost
        // umjesto dupliciranja (cloudflare_zones nema UNIQUE na vhost_id).
        $app->db->run('DELETE FROM cloudflare_zones WHERE vhost_id = ?', [$vhost_id]);
        $app->db->run(
            "INSERT INTO cloudflare_zones (vhost_id, account_id, zone_id, dns_mode, proxy_default)
             VALUES (?, ?, ?, 'cloudflare', ?)",
            [$vhost_id, $account['id'], $zone_id, (int) $proxy]
        );
        return ['ok' => true, 'zone_id' => $zone_id, 'created' => $created];
    }

    /**
     * Auto-provisioning DNS-a za NOVU poddomenu (vhost.create nad poddomenom). Za razliku
     * od apex domene NE kreira novu zonu — dodaje A/AAAA poddomene u postojeću DNS infrastrukturu
     * matičnog vhosta:
     *   - lokalna BIND zona roditelja → kopira root (@) A/AAAA na labelu poddomene,
     *   - ako je roditelj vezan na Cloudflare zonu → doda A zapis poddomene u tu zonu i
     *     poveže poddomenu-vhost na istu zonu (cloudflare_zones) radi AutoSSL DNS-01 + cleanupa.
     * Best-effort po pojedinom kanalu — greška ne ruši kreiranje poddomene.
     *
     * @return array{local: bool, cloudflare: bool}
     */
    public static function subdomain(App $app, int $parent_vhost_id, string $parent_domain, string $label, int $sub_vhost_id): array
    {
        $result = ['local' => false, 'cloudflare' => false];
        $full = $label . '.' . $parent_domain;

        // 1) Lokalna (BIND) zona roditelja → A/AAAA poddomene (kopija root @ → isti server)
        $zone = $app->db->one('SELECT id FROM dns_zones WHERE domain = ?', [$parent_domain]);
        if ($zone !== null) {
            $zone_id = (int) $zone['id'];
            $added = false;
            $had_root = false;
            foreach (['A', 'AAAA'] as $type) {
                $root = $app->db->one(
                    'SELECT content FROM dns_records WHERE zone_id = ? AND name = ? AND type = ? LIMIT 1',
                    [$zone_id, '@', $type]
                );
                if ($root === null) {
                    continue;
                }
                $had_root = true;
                if ($app->db->one('SELECT 1 FROM dns_records WHERE zone_id = ? AND name = ? AND type = ?', [$zone_id, $label, $type]) !== null) {
                    continue; // već postoji (idempotentno)
                }
                $app->db->run(
                    'INSERT INTO dns_records (zone_id, name, type, content, ttl) VALUES (?, ?, ?, ?, 3600)',
                    [$zone_id, $label, $type, $root['content']]
                );
                $added = true;
            }
            if ($added) {
                try {
                    DnsSync::sync($app, $zone_id);
                    $result['local'] = true;
                } catch (\Throwable $e) {
                    error_log('forgepanel: subdomain local DNS sync: ' . $e->getMessage());
                }
            } elseif ($had_root) {
                $result['local'] = true; // zapis je već postojao
            }
        }

        // 2) Cloudflare zona roditelja → A zapis poddomene + veza poddomena-vhost ↔ ista zona
        $cf = $app->db->one(
            'SELECT z.zone_id, z.account_id, z.proxy_default, a.api_token
             FROM cloudflare_zones z JOIN cloudflare_accounts a ON a.id = z.account_id
             WHERE z.vhost_id = ? LIMIT 1',
            [$parent_vhost_id]
        );
        if ($cf !== null) {
            try {
                $client = new CloudflareClient((new Crypto($app->config))->decrypt((string) $cf['api_token']));
                $proxy = (bool) $cf['proxy_default'];
                $client->createRecord((string) $cf['zone_id'], 'A', $full, self::serverIp($app), $proxy, 3600, null);
                // Veza poddomene na istu CF zonu (idempotentno) → AutoSSL DNS-01 + cleanup
                $app->db->run('DELETE FROM cloudflare_zones WHERE vhost_id = ?', [$sub_vhost_id]);
                $app->db->run(
                    "INSERT INTO cloudflare_zones (vhost_id, account_id, zone_id, dns_mode, proxy_default)
                     VALUES (?, ?, ?, 'cloudflare', ?)",
                    [$sub_vhost_id, (int) $cf['account_id'], (string) $cf['zone_id'], (int) $proxy]
                );
                $result['cloudflare'] = true;
            } catch (\Throwable $e) {
                error_log('forgepanel: subdomain CF DNS: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Ukloni s povezane CF zone sve zapise koji pripadaju domeni (apex + poddomene).
     * Veza se traži po vhost_id (cloudflare_zones); best-effort po pojedinom zapisu.
     * Zovi PRIJE brisanja vhosta — CASCADE briše cloudflare_zones vezu.
     */
    public static function cloudflareCleanup(App $app, int $vhost_id, string $domain): void
    {
        $cf = $app->db->one(
            'SELECT z.zone_id, a.api_token FROM cloudflare_zones z
             JOIN cloudflare_accounts a ON a.id = z.account_id
             WHERE z.vhost_id = ? LIMIT 1',
            [$vhost_id]
        );
        if ($cf === null) {
            return;
        }
        $client = new CloudflareClient((new Crypto($app->config))->decrypt((string) $cf['api_token']));
        $zone_id = (string) $cf['zone_id'];
        $domain = strtolower($domain);
        $suffix = '.' . $domain;
        foreach ($client->dnsRecords($zone_id) as $r) {
            $name = strtolower(rtrim((string) ($r['name'] ?? ''), '.'));
            $id = $r['id'] ?? null;
            if (is_string($id) && ($name === $domain || str_ends_with($name, $suffix))) {
                try {
                    $client->deleteRecord($zone_id, $id);
                } catch (\Throwable) {
                    // best-effort — ostatak nastavlja
                }
            }
        }
    }

    /** Javna IPv4 servera (settings.server_ipv4, JSON-enkodirana); fallback 127.0.0.1. */
    private static function serverIp(App $app): string
    {
        $row = $app->db->one('SELECT value FROM settings WHERE `key` = ?', ['server_ipv4']);
        $ip = $row === null ? null : json_decode((string) $row['value'], true);
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '127.0.0.1';
    }
}
