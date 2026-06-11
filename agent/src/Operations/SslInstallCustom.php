<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\CertUtil;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * ssl.install_custom — ručna instalacija kupljenog/vlastitog certifikata.
 * Sve provjere (ključ↔cert, domena, istek, chain) PRIJE pisanja; atomski zapis,
 * nginx config test prije reloada. AutoSSL obnova se za domenu isključuje u web sloju.
 */
final class SslInstallCustom extends Operation
{
    public function validate(array $params): void
    {
        Validator::fqdn($params['domain'] ?? null);
        foreach (['cert_pem', 'key_pem'] as $field) {
            if (!is_string($params[$field] ?? null) || trim($params[$field]) === '') {
                throw new ValidationException("$field je obavezan (PEM)");
            }
            if (strlen($params[$field]) > 65536) {
                throw new ValidationException("$field je prevelik");
            }
        }
        if (isset($params['chain_pem']) && (!is_string($params['chain_pem']) || strlen($params['chain_pem']) > 131072)) {
            throw new ValidationException('chain_pem nije ispravan');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        $cert_pem = trim((string) $params['cert_pem']);
        $key_pem = trim((string) $params['key_pem']);
        $chain_pem = trim((string) ($params['chain_pem'] ?? ''));

        $info = CertUtil::validate($cert_pem, $key_pem, $chain_pem, $domain);

        $ssl_dir = "/etc/forgepanel/ssl/$domain";
        if (!is_dir($ssl_dir)) {
            mkdir($ssl_dir, 0o700, true);
        }
        // fullchain = cert + chain; atomski zamijeni tek kad su oba spremna
        file_put_contents("$ssl_dir/fullchain.pem.new", $cert_pem . "\n" . ($chain_pem !== '' ? $chain_pem . "\n" : ''));
        file_put_contents("$ssl_dir/privkey.pem.new", $key_pem . "\n");
        chmod("$ssl_dir/privkey.pem.new", 0o600);
        rename("$ssl_dir/fullchain.pem.new", "$ssl_dir/fullchain.pem");
        rename("$ssl_dir/privkey.pem.new", "$ssl_dir/privkey.pem");

        if (!Proc::run(['nginx', '-t'])->ok()) {
            throw new \RuntimeException('nginx -t pao nakon instalacije certifikata — provjeri konfiguraciju.');
        }
        if (Systemd::isActive('nginx')) {
            Systemd::reload('nginx');
        }

        return [
            'domain' => $domain,
            'expires_at' => $info['expires_at'],
            'issuer' => $info['issuer'],
            'sans' => $info['sans'],
        ];
    }
}
