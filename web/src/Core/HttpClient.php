<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

/**
 * Sigurnosno otvrdnute zajedničke cURL opcije za SVE odlazne HTTP pozive panela
 * (Cloudflare, Anthropic, billing webhookovi). Jedno mjesto istine umjesto da se
 * TLS verifikacija ponavlja (i zaboravi) po klijentima.
 *
 * Mjere:
 *  - obavezna TLS verifikacija peera i hostname-a (MITM zaštita),
 *  - isključivo https:// na zahtjevu i na redirectima (anti-SSRF: nema skoka na
 *    file://, gopher://, http:// interni endpoint),
 *  - bez automatskog praćenja redirecta prema internim/private metama bez kontrole,
 *  - minimalna TLS verzija 1.2.
 */
final class HttpClient
{
    /**
     * Vrati otvrdnute opcije spremne za curl_setopt_array (spoji sa specifičnima).
     *
     * @return array<int, mixed>
     */
    public static function hardenedOptions(): array
    {
        return [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,           // ne prati redirecte na nepoznate mete
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,       // samo https na izvornom URL-u
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, // i ako se redirect ipak omogući
            CURLOPT_SSL_VERIFYSTATUS => false,          // OCSP staple nije svugdje dostupan
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
    }

    /**
     * Postavi otvrdnute + dodatne opcije na resurs. Dodatne opcije ne smiju
     * pregaziti TLS verifikaciju.
     *
     * @param \CurlHandle $ch
     * @param array<int, mixed> $extra
     */
    public static function apply(\CurlHandle $ch, array $extra): void
    {
        curl_setopt_array($ch, $extra);
        // Otvrdnute opcije idu ZADNJE da ih pozivatelj ne može slučajno oslabiti.
        curl_setopt_array($ch, self::hardenedOptions());
    }

    /**
     * Anti-SSRF provjera za admin-konfigurirane URL-ove (npr. billing webhook):
     * mora biti https i NE smije ciljati loopback/private/link-local/metadata IP.
     */
    public static function isSafePublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])) {
            return false;
        }
        $host = $parts['host'];
        // Riješi sve A/AAAA zapise i odbij ako bilo koji pada u privatni raspon.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $rec) {
                foreach (@dns_get_record($host, $rec) ?: [] as $r) {
                    $ips[] = $r['ip'] ?? $r['ipv6'] ?? '';
                }
            }
        }
        if ($ips === []) {
            return false; // ne može se razriješiti → ne riskiraj
        }
        foreach ($ips as $ip) {
            if ($ip === '' || filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                return false; // privatni / rezervirani / loopback / link-local
            }
        }
        return true;
    }
}
