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
     * Mail: nova domena odmah dobiva i mail domenu (DKIM ključ + red u mail_domains), da
     * se e-mail računi mogu kreirati bez ručnog dodavanja domene na Mail ekranu (isto kao
     * MailController::createDomain, ali kao dio kreiranja web domene).
     * Preskače ako mail (postfix) nije instaliran ili domena već ima mail (idempotentno).
     *
     * Zovi PRIJE localZone()/cloudflare() — tako DKIM TXT zapis uđe u DNS zonu (lokalnu i
     * CF) u istom prolazu (DnsDefaults čita mail_domains.dkim_txt). DKIM je best-effort: ako
     * agent padne, domena se svejedno zavede (mailboxi rade preko DB mapa; DKIM se može
     * naknadno regenerirati), kreiranje web domene se ne ruši.
     *
     * @return ?int  id kreirane mail domene, ili null ako je preskočeno
     */
    public static function mailDomain(App $app, string $domain, int $subscription_id): ?int
    {
        $installed = $app->db->one("SELECT 1 FROM components WHERE name = 'postfix' AND status = 'installed'") !== null;
        if (!$installed) {
            return null;
        }
        if ($app->db->one('SELECT 1 FROM mail_domains WHERE domain = ?', [$domain]) !== null) {
            return null;
        }

        // DKIM ključ per domena (best-effort) — agentova greška ne smije srušiti kreiranje domene
        $selector = 'forge';
        $dkim_txt = null;
        try {
            $dkim = $app->agent->call('mail.domain_add', ['domain' => $domain], timeout_s: 60);
            $selector = (string) ($dkim['selector'] ?? 'forge');
            $dkim_txt = $dkim['dkim_txt'] ?? null;
        } catch (\Throwable $e) {
            error_log('forgepanel: vhost.create mail.domain_add (' . $domain . '): ' . $e->getMessage());
        }

        $app->db->run(
            'INSERT INTO mail_domains (domain, subscription_id, dkim_selector, dkim_txt) VALUES (?, ?, ?, ?)',
            [$domain, $subscription_id, $selector, $dkim_txt]
        );
        return $app->db->lastId();
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

        // 2) Cloudflare: ako je MATIČNA domena na CF-u (bilo preko per-vhost veze
        //    cloudflare_zones, bilo samo preko dns_zones.cf_account_id "exporta"), dodaj A
        //    zapis poddomene u CF zonu i poveži poddomenu-vhost s tom zonom. Time agentov
        //    AutoSSL bira DNS-01 (radi i za proxied domene) — http-01 bi pao jer poddomena
        //    nije javno razrješiva (autoritativni NS matične domene je Cloudflare).
        $cf = self::resolveParentCloudflare($app, $parent_vhost_id, $parent_domain);
        if ($cf !== null) {
            // Vezu (cloudflare_zones) zapiši PRVO i bezuvjetno — AutoSSL DNS-01 ovisi SAMO o
            // njoj. Da je iza createRecord, greška u API zapisu (npr. konflikt) bi je preskočila
            // i poddomena bi opet pala na http-01.
            $app->db->run('DELETE FROM cloudflare_zones WHERE vhost_id = ?', [$sub_vhost_id]);
            $app->db->run(
                "INSERT INTO cloudflare_zones (vhost_id, account_id, zone_id, dns_mode, proxy_default)
                 VALUES (?, ?, ?, 'cloudflare', ?)",
                [$sub_vhost_id, $cf['account_id'], $cf['zone_id'], $cf['proxy_default']]
            );
            $result['cloudflare'] = true;
            // A zapis poddomene u CF zonu — best-effort, izolirano (da ne sruši vezu gore)
            try {
                $client = new CloudflareClient((new Crypto($app->config))->decrypt($cf['api_token']));
                $client->createRecord($cf['zone_id'], 'A', $full, self::serverIp($app), (bool) $cf['proxy_default'], 3600, null);
            } catch (\Throwable $e) {
                error_log('forgepanel: subdomain CF A-record: ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * CF kontekst MATIČNE domene za poddomenu. Dva izvora:
     *  a) per-vhost veza (cloudflare_zones) — domena kreirana s odabranim CF računom;
     *  b) fallback: zona je samo "exportana" na CF (dns_zones.cf_account_id, bez per-vhost
     *     veze) → zone_id se dohvaća preko CF API-ja po imenu domene.
     * Bez (a)/(b) → null (matična nije na CF-u; poddomena ide http-01 preko lokalne zone).
     *
     * @return array{account_id: int, zone_id: string, api_token: string, proxy_default: int}|null
     */
    private static function resolveParentCloudflare(App $app, int $parent_vhost_id, string $parent_domain): ?array
    {
        $link = $app->db->one(
            'SELECT z.zone_id, z.account_id, z.proxy_default, a.api_token
             FROM cloudflare_zones z JOIN cloudflare_accounts a ON a.id = z.account_id
             WHERE z.vhost_id = ? AND z.dns_mode = \'cloudflare\' AND a.status = \'active\' LIMIT 1',
            [$parent_vhost_id]
        );
        if ($link !== null) {
            return [
                'account_id' => (int) $link['account_id'],
                'zone_id' => (string) $link['zone_id'],
                'api_token' => (string) $link['api_token'],
                'proxy_default' => (int) $link['proxy_default'],
            ];
        }

        // (b) exportana zona — nema per-vhost veze; nađi zone_id preko CF API-ja po imenu
        $acct = $app->db->one(
            'SELECT a.id, a.api_token FROM dns_zones z JOIN cloudflare_accounts a ON a.id = z.cf_account_id
             WHERE z.domain = ? AND a.status = \'active\' LIMIT 1',
            [$parent_domain]
        );
        if ($acct === null) {
            // Dijagnostika: zona JE vezana na CF račun, ali račun nije aktivan (token
            // opozvan/nevažeći) → poddomena tiho pada na http-01; ostavi trag u logu.
            $inactive = $app->db->one(
                'SELECT 1 FROM dns_zones z JOIN cloudflare_accounts a ON a.id = z.cf_account_id
                 WHERE z.domain = ? AND a.status <> \'active\' LIMIT 1',
                [$parent_domain]
            );
            if ($inactive !== null) {
                error_log("forgepanel: subdomain CF: matična zona $parent_domain ima neaktivan CF račun — DNS-01 preskočen, provjeri token");
            }
            return null;
        }
        try {
            $client = new CloudflareClient((new Crypto($app->config))->decrypt((string) $acct['api_token']));
            foreach ($client->zones() as $z) {
                if (strtolower((string) ($z['name'] ?? '')) === strtolower($parent_domain)) {
                    return [
                        'account_id' => (int) $acct['id'],
                        'zone_id' => (string) $z['id'],
                        'api_token' => (string) $acct['api_token'],
                        // exportani zapisi su grey (ne-proxied) — poddomena jednako, da
                        // radi izravno na origin (i http-01 i DNS-01 prolaze)
                        'proxy_default' => 0,
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('forgepanel: subdomain CF zone lookup: ' . $e->getMessage());
        }
        return null;
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
