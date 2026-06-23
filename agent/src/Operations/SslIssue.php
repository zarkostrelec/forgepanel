<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Acme;
use ForgePanel\Agent\System\CloudflareDns;
use ForgePanel\Agent\System\Crypto;
use ForgePanel\Agent\System\Dns01Provider;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/** ssl.issue — AutoSSL: Let's Encrypt http-01 izdavanje, atomski zapis certova, nginx reload. */
final class SslIssue extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        if (!is_array($params['hostnames'] ?? null) || $params['hostnames'] === []) {
            throw new ValidationException('hostnames mora biti neprazna lista');
        }
        foreach ($params['hostnames'] as $hostname) {
            Validator::fqdn($hostname, 'hostname');
        }
        if (!filter_var($params['contact_email'] ?? null, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('contact_email nije ispravan');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $hostnames = array_map(static fn ($h) => Validator::fqdn($h, 'hostname'), $params['hostnames']);
        $primary = $hostnames[0];

        $context->output("AutoSSL: izdajem certifikat za $primary");
        $context->progress(10);

        $dns01 = $this->cloudflareDns01($params, $context);
        try {
            $acme = new Acme();
            $cert = $acme->issue($hostnames, (string) $params['contact_email'], $context->output(...), $dns01);
        } catch (\Throwable $e) {
            $this->db->run(
                "UPDATE ssl_certs SET status = 'error', last_error = ? WHERE hostname = ?",
                [mb_substr($e->getMessage(), 0, 2000), $primary]
            );
            throw $e;
        }
        $context->progress(80);

        $ssl_dir = "/etc/forgepanel/ssl/$primary";
        if (!is_dir($ssl_dir)) {
            mkdir($ssl_dir, 0o700, true);
        }
        file_put_contents("$ssl_dir/fullchain.pem.new", $cert['fullchain']);
        file_put_contents("$ssl_dir/privkey.pem.new", $cert['privkey']);
        chmod("$ssl_dir/privkey.pem.new", 0o600);
        rename("$ssl_dir/fullchain.pem.new", "$ssl_dir/fullchain.pem");
        rename("$ssl_dir/privkey.pem.new", "$ssl_dir/privkey.pem");

        if (Systemd::isActive('nginx')) {
            Systemd::reload('nginx');
        }

        $parsed = openssl_x509_parse($cert['fullchain']);
        $expires_at = is_array($parsed) ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t']) : null;

        // Registar certifikata: postojeći red se ažurira, novi se kreira (panel cert i sl.)
        $updated = $this->db->run(
            "UPDATE ssl_certs SET status = 'active', last_error = NULL, expires_at = ?,
                    cert_path = ?, key_path = ?, type = 'letsencrypt'
             WHERE hostname = ?",
            [$expires_at, "$ssl_dir/fullchain.pem", "$ssl_dir/privkey.pem", $primary]
        )->rowCount();
        if ($updated === 0) {
            $this->db->run(
                "INSERT INTO ssl_certs (vhost_id, hostname, type, cert_path, key_path, expires_at, status)
                 VALUES (?, ?, 'letsencrypt', ?, ?, ?, 'active')",
                [
                    isset($params['vhost_id']) ? (int) $params['vhost_id'] : null,
                    $primary,
                    "$ssl_dir/fullchain.pem",
                    "$ssl_dir/privkey.pem",
                    $expires_at,
                ]
            );
        }

        $context->progress(100);
        $context->output("Certifikat aktivan, vrijedi do $expires_at");

        return ['hostname' => $primary, 'expires_at' => $expires_at, 'cert_path' => "$ssl_dir/fullchain.pem", 'key_path' => "$ssl_dir/privkey.pem"];
    }

    /**
     * DNS-01 provider ako je domena vezana uz aktivni Cloudflare račun (cloudflare_zones).
     * Tada AutoSSL radi i za proxied domene — http-01 bi pao na CF proxyju (526/redirect).
     * Inače null → http-01.
     * @param array<string, mixed> $params
     */
    private function cloudflareDns01(array $params, TaskContext $context): ?Dns01Provider
    {
        if (!isset($params['vhost_id'])) {
            return null;
        }
        $cf = $this->db->one(
            'SELECT z.zone_id, a.api_token
             FROM cloudflare_zones z JOIN cloudflare_accounts a ON a.id = z.account_id
             WHERE z.vhost_id = ? AND z.dns_mode = \'cloudflare\' AND a.status = \'active\'
             LIMIT 1',
            [(int) $params['vhost_id']]
        );
        if ($cf === null) {
            return null;
        }
        try {
            $token = Crypto::fromConfig($this->config)->decrypt((string) $cf['api_token']);
        } catch (\Throwable $e) {
            $context->output('AutoSSL: CF token nedostupan (' . $e->getMessage() . ') — koristim http-01.');
            return null;
        }
        $context->output('AutoSSL: DNS-01 challenge preko Cloudflarea (zona ' . $cf['zone_id'] . ')');
        return new CloudflareDns($token, (string) $cf['zone_id']);
    }
}
