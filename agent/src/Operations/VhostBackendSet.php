<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\ApacheConf;
use ForgePanel\Agent\System\LegacyPhp;
use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/**
 * vhost.backend_set — per-domena izbor: čisti nginx+FPM (brže, default)
 * ili nginx → Apache (za .htaccess/WordPress). Dugotrajno jer prvi
 * Apache vhost može povući instalaciju Apachea.
 */
final class VhostBackendSet extends Operation
{
    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        Validator::phpVersion($params['php_version'] ?? null);
        Validator::oneOf($params['backend'] ?? null, ['nginx', 'nginx_apache', 'php_legacy'], 'backend');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);
        $php_version = Validator::phpVersion($params['php_version']);
        $backend = (string) $params['backend'];
        $sys_user = 'vh_' . $vhost_id;
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;
        $docroot = $vhost_root . '/httpdocs';

        // Legacy PHP (Docker) — vlastita grana: izgradi image, digni FPM container,
        // nginx fastcgi na container port. DB radi preko mountanog MariaDB socketa.
        if ($backend === 'php_legacy') {
            ApacheConf::removeVhost($domain);
            $context->output('Legacy PHP ' . LegacyPhp::PHP_VERSION . ' (Docker) za ' . $domain);
            LegacyPhp::ensureImage($context->output(...));
            $context->progress(50);
            $port = LegacyPhp::up($vhost_id, $domain, $vhost_root, $context->output(...));
            $context->progress(80);
            $context->output("nginx config (legacy) + test + reload");
            NginxConf::writeAndReload(
                NginxConf::VHOST_CONF_DIR . "/$domain.conf",
                NginxConf::vhostLegacyTemplate($domain, $docroot, $port)
            );
            $this->db->run('UPDATE vhosts SET web_backend = ? WHERE id = ?', ['php_legacy', $vhost_id]);
            $context->progress(100);
            $context->output("Backend za $domain: legacy PHP " . LegacyPhp::PHP_VERSION);
            return ['domain' => $domain, 'web_backend' => 'php_legacy', 'php' => LegacyPhp::PHP_VERSION];
        }

        // Prelazak s legacy na nginx/apache → ukloni container
        LegacyPhp::down($vhost_id, $context->output(...));

        if ($backend === 'nginx_apache') {
            ApacheConf::ensureInstalled($context->output(...));
            $context->output("Apache vhost za $domain");
            ApacheConf::writeVhost($domain, $docroot, $sys_user, $vhost_root);
            $context->progress(60);
        } else {
            ApacheConf::removeVhost($domain);
            $context->progress(40);
        }

        $context->output("nginx config ($backend) + test + reload");
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$domain.conf",
            NginxConf::templateFor($backend, $domain, $docroot, $php_version, $sys_user)
        );

        $this->db->run('UPDATE vhosts SET web_backend = ? WHERE id = ?', [$backend, $vhost_id]);
        $context->progress(100);
        $context->output("Backend za $domain: $backend");

        return ['domain' => $domain, 'web_backend' => $backend];
    }
}
