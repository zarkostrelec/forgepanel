<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Apt;
use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\System\Systemd;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/**
 * waf.toggle — ModSecurity + OWASP CRS per-vhost on/off i paranoia level.
 * Rješava glavnu manu CRS-a (false positive horor) preko panela.
 */
final class WafToggle extends Operation
{
    public const WAF_DIR = '/etc/nginx/forgepanel/waf';

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        $enabled = $params['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw new \ForgePanel\Agent\ValidationException('enabled mora biti bool');
        }
        $paranoia = $params['paranoia'] ?? 1;
        if (!is_int($paranoia) || $paranoia < 1 || $paranoia > 4) {
            throw new \ForgePanel\Agent\ValidationException('paranoia mora biti 1–4');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $domain = Validator::fqdn($params['domain']);
        $enabled = (bool) $params['enabled'];
        $paranoia = (int) ($params['paranoia'] ?? 1);

        $this->ensureModsec($context);
        if (!is_dir(self::WAF_DIR)) {
            mkdir(self::WAF_DIR, 0o755, true);
        }
        $rules_file = self::WAF_DIR . "/$domain.conf";

        if ($enabled) {
            $context->output("WAF ON za $domain (paranoia $paranoia)");
            file_put_contents($rules_file, implode("\n", [
                'modsecurity on;',
                'modsecurity_rules \'SecAction "id:900000,phase:1,nolog,pass,t:none,setvar:tx.paranoia_level=' . $paranoia . '"\';',
                'modsecurity_rules_file /etc/nginx/forgepanel/waf/crs-setup.conf;',
            ]) . "\n");
        } else {
            $context->output("WAF OFF za $domain");
            file_put_contents($rules_file, "modsecurity off;\n");
        }

        // Test + reload (NginxConf radi rollback ako padne)
        if (Proc::run(['nginx', '-t'])->ok()) {
            Systemd::reload('nginx');
        } else {
            @unlink($rules_file);
            throw new \RuntimeException('nginx -t pao nakon WAF promjene — vraćeno');
        }

        $this->db->run(
            "INSERT INTO waf_rules (vhost_id, rule_id, action) VALUES (?, 'engine', ?)
             ON DUPLICATE KEY UPDATE action = VALUES(action)",
            [(int) $params['vhost_id'], $enabled ? 'block' : 'whitelist']
        );
        return ['domain' => $domain, 'enabled' => $enabled, 'paranoia' => $paranoia];
    }

    private function ensureModsec(TaskContext $context): void
    {
        if (is_file('/etc/nginx/forgepanel/waf/crs-setup.conf')) {
            return;
        }
        $context->output('Instaliram ModSecurity + OWASP CRS');
        // nginx.org build dolazi s ngx_http_modsecurity; CRS iz repoa
        Apt::install(['modsecurity-crs'], $context->output(...));
        if (!is_dir(self::WAF_DIR)) {
            mkdir(self::WAF_DIR, 0o755, true);
        }
        // Pokaži na sistemski CRS setup ako postoji
        $crs = is_file('/usr/share/modsecurity-crs/owasp-crs.load')
            ? '/usr/share/modsecurity-crs/owasp-crs.load'
            : '/etc/modsecurity/crs/crs-setup.conf';
        @copy($crs, '/etc/nginx/forgepanel/waf/crs-setup.conf');
    }
}
