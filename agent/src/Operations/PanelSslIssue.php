<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Acme;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * ssl.panel_issue — AutoSSL za panelov vlastiti hostname (:8443).
 *
 * Hostname i ACME kontakt dolaze ISKLJUČIVO iz agent.ini (panel_fqdn, acme_email),
 * nikad iz parametara — web sloj ne može preusmjeriti izdavanje na tuđi hostname.
 * Cert se piše u /etc/forgepanel/ssl/panel/ (panel nginx config pokazuje tamo,
 * installer tamo stavlja privremeni self-signed). Scheduler enqueua ovaj task
 * dok god je panel na self-signed certu ili mu cert istječe za <30 dana.
 */
final class PanelSslIssue extends Operation
{
    private const SSL_DIR = '/etc/forgepanel/ssl/panel';

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        $fqdn = $this->config->get('panel_fqdn', '');
        if ($fqdn === null || $fqdn === '') {
            throw new ValidationException('panel_fqdn nije postavljen u agent.ini');
        }
        Validator::fqdn($fqdn, 'panel_fqdn');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $fqdn = Validator::fqdn($this->config->get('panel_fqdn'), 'panel_fqdn');
        $contact = (string) $this->config->get('acme_email', 'admin@' . $fqdn);

        $context->output("AutoSSL: izdajem panel certifikat za {$fqdn}\n");
        $context->progress(10);

        try {
            $cert = (new Acme())->issue([$fqdn], $contact, $context->output(...));
        } catch (\Throwable $e) {
            $this->db->run(
                "UPDATE ssl_certs SET status = 'error', last_error = ? WHERE hostname = ? AND vhost_id IS NULL",
                [mb_substr($e->getMessage(), 0, 2000), $fqdn]
            );
            throw $e;
        }
        $context->progress(80);

        if (!is_dir(self::SSL_DIR)) {
            mkdir(self::SSL_DIR, 0o700, true);
        }
        $dir = self::SSL_DIR;
        file_put_contents("$dir/fullchain.pem.new", $cert['fullchain']);
        file_put_contents("$dir/privkey.pem.new", $cert['privkey']);
        chmod("$dir/privkey.pem.new", 0o600);
        rename("$dir/fullchain.pem.new", "$dir/fullchain.pem");
        rename("$dir/privkey.pem.new", "$dir/privkey.pem");

        if (Systemd::isActive('nginx')) {
            Systemd::reload('nginx');
        }

        $parsed = openssl_x509_parse($cert['fullchain']);
        $expires_at = is_array($parsed) ? date('Y-m-d H:i:s', (int) $parsed['validTo_time_t']) : null;

        $updated = $this->db->run(
            "UPDATE ssl_certs SET status = 'active', last_error = NULL, expires_at = ?,
                    cert_path = ?, key_path = ?, type = 'letsencrypt'
             WHERE hostname = ? AND vhost_id IS NULL",
            [$expires_at, "$dir/fullchain.pem", "$dir/privkey.pem", $fqdn]
        )->rowCount();
        if ($updated === 0) {
            $this->db->run(
                "INSERT INTO ssl_certs (vhost_id, hostname, type, cert_path, key_path, expires_at, status, auto_renew)
                 VALUES (NULL, ?, 'letsencrypt', ?, ?, ?, 'active', 1)",
                [$fqdn, "$dir/fullchain.pem", "$dir/privkey.pem", $expires_at]
            );
        }

        $context->output("Panel certifikat instaliran (vrijedi do {$expires_at})\n");
        $context->progress(100);

        return ['hostname' => $fqdn, 'expires_at' => $expires_at];
    }
}
