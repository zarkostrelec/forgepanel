<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Fs;
use ForgePanel\Agent\System\NginxConf;
use ForgePanel\Agent\System\PhpFpm;
use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/**
 * vhost.create — sistemski user vh_<id>, direktoriji, FPM pool (izolacija),
 * nginx vhost (privremeni self-signed cert dok AutoSSL ne izda pravi),
 * cgroup v2 kvote iz plana.
 */
final class VhostCreate extends Operation
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
        if (isset($params['redirect_target']) && $params['redirect_target'] !== '') {
            Validator::fqdn($params['redirect_target'], 'redirect_target');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);

        // Redirect vhost: bez sistemskog usera/FPM poola/docroota — samo nginx redirect.
        if (isset($params['redirect_target']) && $params['redirect_target'] !== '') {
            return $this->createRedirect(
                $vhost_id, $domain,
                Validator::fqdn($params['redirect_target'], 'redirect_target'),
                (int) ($params['redirect_code'] ?? 301), $context
            );
        }

        $php_version = Validator::phpVersion($params['php_version']);
        $sys_user = 'vh_' . $vhost_id;
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;
        $docroot = $vhost_root . '/httpdocs';

        $context->output("Kreiram sistemskog usera $sys_user");
        if (!Proc::run(['id', $sys_user])->ok()) {
            Proc::mustRun(['useradd', '--system', '--shell', '/usr/sbin/nologin',
                '--home-dir', $vhost_root, '--no-create-home', $sys_user]);
        }
        $context->progress(15);

        $context->output("Kreiram direktorije u $vhost_root");
        foreach (['', '/httpdocs', '/logs', '/tmp', '/private'] as $sub) {
            if (!is_dir($vhost_root . $sub)) {
                mkdir($vhost_root . $sub, 0o755, true);
            }
        }
        chmod($vhost_root . '/tmp', 0o770);
        Proc::mustRun(['chown', '-R', "$sys_user:$sys_user", $vhost_root]);
        // nginx worker treba ulaz u docroot radi posluživanja statičkih fileova
        Proc::mustRun(['usermod', '-aG', $sys_user, 'nginx']);
        file_put_contents($docroot . '/index.html', "<!doctype html><title>$domain</title><h1>$domain</h1><p>ForgePanel</p>");
        Proc::mustRun(['chown', "$sys_user:$sys_user", $docroot . '/index.html']);

        // Disk project quota (ext4/xfs) — enforcement disk kvote PLANA po vhostu (projid = vhost_id).
        // Best-effort: zahtijeva fs montiran s prjquota (installer prerequisite); inače no-op, ne ruši create.
        if (isset($params['disk_bytes']) && (int) $params['disk_bytes'] > 0) {
            $ok = Fs::setProjectQuota($vhost_root, (int) $params['disk_bytes'], $vhost_id);
            $context->output($ok
                ? 'Disk project quota postavljena (' . (int) $params['disk_bytes'] . ' B)'
                : 'Disk project quota preskočena (fs bez prjquota?) — best-effort');
        }
        $context->progress(35);

        $context->output("PHP-FPM {$php_version} dedicirani pool/servis ($sys_user, ondemand, open_basedir)");
        PhpFpm::writePool($php_version, $sys_user, $vhost_root);
        $context->progress(55);

        if (isset($params['cpu_quota_pct'], $params['memory_max_bytes'], $params['tasks_max'])) {
            $context->output('Per-vhost cgroup kvote (CPUQuota/MemoryMax/TasksMax) na dediciranom FPM servisu');
            PhpFpm::setLimits(
                $sys_user,
                (int) $params['cpu_quota_pct'],
                (int) $params['memory_max_bytes'],
                (int) $params['tasks_max'],
            );
        }
        $context->progress(65);

        $context->output('Privremeni self-signed certifikat (AutoSSL će izdati pravi)');
        $ssl_dir = "/etc/forgepanel/ssl/$domain";
        if (!is_file("$ssl_dir/fullchain.pem")) {
            mkdir($ssl_dir, 0o700, true);
            Proc::mustRun(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                '-keyout', "$ssl_dir/privkey.pem", '-out', "$ssl_dir/fullchain.pem",
                '-days', '7', '-subj', "/CN=$domain"]);
        }
        $context->progress(75);

        $context->output('nginx vhost config + nginx -t + reload');
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$domain.conf",
            NginxConf::vhostTemplate($domain, $docroot, $php_version, $sys_user)
        );
        $this->db->run("UPDATE vhosts SET status = 'active' WHERE id = ?", [$vhost_id]);

        // Inicijalne statistike (disk + tip aplikacije) za Siteovi listu — best-effort
        try {
            [$disk, $app] = \ForgePanel\Agent\System\VhostStats::collect($vhost_root, $docroot);
            $this->db->run('UPDATE vhosts SET disk_bytes = ?, app_type = ?, stats_at = NOW() WHERE id = ?', [$disk, $app, $vhost_id]);
        } catch (\Throwable) {
            // statistike nisu kritične za kreiranje vhosta
        }

        $context->progress(100);
        $context->output("Vhost $domain kreiran.");

        return ['domain' => $domain, 'sys_user' => $sys_user, 'docroot' => $docroot];
    }

    /** Redirect vhost: samo nginx 301/302 config + self-signed cert (AutoSSL izda pravi). */
    private function createRedirect(int $vhost_id, string $domain, string $target, int $code, TaskContext $context): array
    {
        $code = in_array($code, [301, 302], true) ? $code : 301;
        $vhost_root = Validator::VHOST_ROOT . '/' . $domain;

        $context->output("Redirect vhost: $domain → $target ($code)");
        if (!is_dir($vhost_root)) {
            mkdir($vhost_root, 0o755, true);
        }
        $context->progress(35);

        $context->output('Privremeni self-signed certifikat (AutoSSL će izdati pravi)');
        $ssl_dir = "/etc/forgepanel/ssl/$domain";
        if (!is_file("$ssl_dir/fullchain.pem")) {
            mkdir($ssl_dir, 0o700, true);
            Proc::mustRun(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                '-keyout', "$ssl_dir/privkey.pem", '-out', "$ssl_dir/fullchain.pem",
                '-days', '7', '-subj', "/CN=$domain"]);
        }
        $context->progress(65);

        $context->output('nginx redirect config + nginx -t + reload');
        NginxConf::writeAndReload(
            NginxConf::VHOST_CONF_DIR . "/$domain.conf",
            NginxConf::vhostRedirectTemplate($domain, $target, $code)
        );
        $this->db->run("UPDATE vhosts SET status = 'active' WHERE id = ?", [$vhost_id]);

        $context->progress(100);
        $context->output("Redirect vhost $domain → $target kreiran.");

        return ['domain' => $domain, 'redirect_target' => $target, 'redirect_code' => $code];
    }
}
