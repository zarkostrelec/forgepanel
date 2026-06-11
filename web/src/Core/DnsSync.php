<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/** Zajednička sinkronizacija DNS zone u BIND kroz agent (koriste dns i mail moduli). */
final class DnsSync
{
    public static function sync(App $app, int $zone_id): void
    {
        $zone = $app->db->one('SELECT * FROM dns_zones WHERE id = ?', [$zone_id]);
        if ($zone === null) {
            throw new HttpException(404, 'not_found');
        }
        $serial = max((int) $zone['serial'] + 1, time());
        $records = $app->db->all(
            'SELECT name, type, content, ttl, prio FROM dns_records WHERE zone_id = ?',
            [$zone_id]
        );
        $panel_fqdn = $app->config->get('panel_fqdn', (string) gethostname());

        $app->agent->call('dns.zone_write', [
            'domain' => $zone['domain'],
            'serial' => $serial,
            'ns1' => "ns1.$panel_fqdn",
            'ns2' => "ns2.$panel_fqdn",
            'records' => array_map(static fn (array $r) => [
                'name' => (string) $r['name'],
                'type' => (string) $r['type'],
                'content' => (string) $r['content'],
                'ttl' => (int) $r['ttl'],
                'prio' => $r['prio'] === null ? null : (int) $r['prio'],
            ], $records),
        ], timeout_s: 30);

        $app->db->run('UPDATE dns_zones SET serial = ? WHERE id = ?', [$serial, $zone_id]);
    }
}
