<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Standardni set DNS zapisa za novu domenu — jedan izvor istine za lokalnu BIND
 * zonu (DnsController) i Cloudflare provisioning (DomainProvision). Time lokalna
 * zona i CF zona za istu domenu uvijek dobiju identičan set zapisa.
 */
final class DnsDefaults
{
    /**
     * @param ?array{dkim_selector?: string, dkim_txt?: ?string} $mail  mail_domains red ako domena ima mail
     * @return list<array{name: string, type: string, content: string, ttl: int, prio: ?int, proxy: bool}>
     *         `proxy` = smije li zapis ići kroz CF proxy (samo A root/www; mail/MX/TXT/CAA nikad)
     */
    public static function records(string $domain, string $server_ip, ?array $mail = null): array
    {
        $recs = [
            ['name' => '@',      'type' => 'A',   'content' => $server_ip,                    'ttl' => 3600, 'prio' => null, 'proxy' => true],
            ['name' => 'www',    'type' => 'A',   'content' => $server_ip,                    'ttl' => 3600, 'prio' => null, 'proxy' => true],
            ['name' => 'mail',   'type' => 'A',   'content' => $server_ip,                    'ttl' => 3600, 'prio' => null, 'proxy' => false],
            ['name' => '@',      'type' => 'MX',  'content' => "mail.$domain",                'ttl' => 3600, 'prio' => 10,   'proxy' => false],
            ['name' => '@',      'type' => 'TXT', 'content' => 'v=spf1 a mx ~all',            'ttl' => 3600, 'prio' => null, 'proxy' => false],
            ['name' => '_dmarc', 'type' => 'TXT', 'content' => "v=DMARC1; p=quarantine; rua=mailto:dmarc@$domain", 'ttl' => 3600, 'prio' => null, 'proxy' => false],
            ['name' => '@',      'type' => 'CAA', 'content' => '0 issue "letsencrypt.org"',   'ttl' => 3600, 'prio' => null, 'proxy' => false],
        ];
        if ($mail !== null && !empty($mail['dkim_txt'])) {
            $recs[] = [
                'name' => ($mail['dkim_selector'] ?? 'forge') . '._domainkey',
                'type' => 'TXT', 'content' => (string) $mail['dkim_txt'],
                'ttl' => 3600, 'prio' => null, 'proxy' => false,
            ];
        }
        return $recs;
    }
}
