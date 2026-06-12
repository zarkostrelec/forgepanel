<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\Config;
use ForgePanel\Agent\Db;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * Mail stack: Postfix + Dovecot + Rspamd.
 * Panel baza je izvor istine — Postfix (proxy:mysql mape) i Dovecot (SQL auth)
 * čitaju mail_domains/mailboxes/mail_aliases direktno, kroz zaseban read-only
 * DB user. Mailbox CRUD u panelu ne treba nikakvu sinkronizaciju.
 */
final class MailConf
{
    public const VMAIL_DIR = '/var/vmail';
    public const DKIM_DIR = '/var/lib/rspamd/dkim';
    public const DKIM_SELECTOR = 'forge';
    private const SQL_DIR = '/etc/postfix/sql';

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
    ) {
    }

    public function setup(?\Closure $log = null): void
    {
        $log ??= static fn (string $s) => null;
        $fqdn = $this->config->get('panel_fqdn', (string) gethostname());

        $log("Instaliram Postfix + Dovecot + Rspamd\n");
        // Preseed: postfix bez interaktivnih pitanja
        Proc::mustRun(
            ['debconf-set-selections'],
            stdin: "postfix postfix/main_mailer_type select Internet Site\npostfix postfix/mailname string {$fqdn}\n"
        );
        Apt::install([
            'postfix', 'postfix-mysql',
            'dovecot-imapd', 'dovecot-pop3d', 'dovecot-lmtpd', 'dovecot-mysql',
            'rspamd',
        ], $log);

        $log("vmail user + direktoriji\n");
        if (!Proc::run(['id', 'vmail'])->ok()) {
            Proc::mustRun(['useradd', '--system', '--shell', '/usr/sbin/nologin',
                '--home-dir', self::VMAIL_DIR, '--create-home', 'vmail']);
        }
        foreach ([self::VMAIL_DIR => 'vmail', self::DKIM_DIR => '_rspamd'] as $dir => $owner) {
            if (!is_dir($dir)) {
                mkdir($dir, 0o750, true);
            }
            Proc::mustRun(['chown', "$owner:$owner", $dir]);
        }

        $log("Read-only DB user za mail servise\n");
        $mail_db_pass = bin2hex(random_bytes(24));
        $quoted = $this->db->pdo()->quote($mail_db_pass);
        foreach (['localhost', '127.0.0.1'] as $host) {
            $this->db->pdo()->exec("CREATE USER IF NOT EXISTS 'forgepanel_mail'@'$host' IDENTIFIED BY $quoted");
            $this->db->pdo()->exec("ALTER USER 'forgepanel_mail'@'$host' IDENTIFIED BY $quoted");
            foreach (['mail_domains', 'mailboxes', 'mail_aliases'] as $table) {
                $this->db->pdo()->exec("GRANT SELECT ON forgepanel.$table TO 'forgepanel_mail'@'$host'");
            }
        }
        $this->db->pdo()->exec('FLUSH PRIVILEGES');

        $this->writePostfixMaps($mail_db_pass);
        $this->configurePostfix($fqdn, $log);
        $this->configureDovecot($mail_db_pass, $log);
        $this->configureRspamd($log);

        $log("Pokrećem servise\n");
        foreach (['postfix', 'dovecot', 'rspamd'] as $service) {
            Systemd::enableNow($service);
        }

        foreach ([
            ['postfix', '["postfix","postfix-mysql"]'],
            ['dovecot', '["dovecot-imapd","dovecot-pop3d","dovecot-lmtpd","dovecot-mysql"]'],
            ['rspamd', '["rspamd"]'],
        ] as [$name, $packages]) {
            $this->db->run(
                "INSERT INTO components (name, status, packages) VALUES (?, 'installed', ?)
                 ON DUPLICATE KEY UPDATE status = 'installed'",
                [$name, $packages]
            );
        }
    }

    private function writePostfixMaps(string $mail_db_pass): void
    {
        if (!is_dir(self::SQL_DIR)) {
            mkdir(self::SQL_DIR, 0o750, true);
        }
        // postfix user (proxymap) mora moći ući u direktorij i čitati mape
        Proc::mustRun(['chgrp', 'postfix', self::SQL_DIR]);
        $maps = [
            'virtual_domains.cf' => "SELECT 1 FROM mail_domains WHERE domain='%s'",
            'virtual_mailboxes.cf' => "SELECT 1 FROM mailboxes m JOIN mail_domains d ON d.id=m.mail_domain_id"
                . " WHERE m.local_part='%u' AND d.domain='%d' AND m.status='active'",
            'virtual_aliases.cf' => "SELECT a.destination FROM mail_aliases a JOIN mail_domains d ON d.id=a.mail_domain_id"
                . " WHERE a.source='%s'",
            'virtual_catchall.cf' => "SELECT d.catchall_target FROM mail_domains d"
                . " WHERE d.domain='%d' AND d.catchall_target IS NOT NULL",
        ];
        foreach ($maps as $file => $query) {
            file_put_contents(self::SQL_DIR . "/$file", implode("\n", [
                'user = forgepanel_mail',
                "password = $mail_db_pass",
                'hosts = 127.0.0.1',
                'dbname = forgepanel',
                "query = $query",
            ]) . "\n");
            chmod(self::SQL_DIR . "/$file", 0o640);
            Proc::mustRun(['chgrp', 'postfix', self::SQL_DIR . "/$file"]);
        }
    }

    private function configurePostfix(string $fqdn, \Closure $log): void
    {
        $log("Postfix konfiguracija (postconf)\n");
        $settings = [
            'myhostname' => $fqdn,
            'virtual_mailbox_domains' => 'proxy:mysql:' . self::SQL_DIR . '/virtual_domains.cf',
            'virtual_mailbox_maps' => 'proxy:mysql:' . self::SQL_DIR . '/virtual_mailboxes.cf',
            'virtual_alias_maps' => 'proxy:mysql:' . self::SQL_DIR . '/virtual_aliases.cf'
                . ', proxy:mysql:' . self::SQL_DIR . '/virtual_catchall.cf',
            'virtual_transport' => 'lmtp:unix:private/dovecot-lmtp',
            'smtpd_sasl_type' => 'dovecot',
            'smtpd_sasl_path' => 'private/auth',
            'smtpd_sasl_auth_enable' => 'yes',
            'smtpd_tls_cert_file' => '/etc/forgepanel/ssl/panel/fullchain.pem',
            'smtpd_tls_key_file' => '/etc/forgepanel/ssl/panel/privkey.pem',
            'smtpd_tls_security_level' => 'may',
            'smtp_tls_security_level' => 'may',
            'smtpd_milters' => 'inet:127.0.0.1:11332',
            'non_smtpd_milters' => 'inet:127.0.0.1:11332',
            'milter_default_action' => 'accept',
            'milter_protocol' => '6',
            'smtpd_relay_restrictions' => 'permit_mynetworks, permit_sasl_authenticated, reject_unauth_destination',
            'message_size_limit' => '52428800',
        ];
        foreach ($settings as $key => $value) {
            Proc::mustRun(['postconf', '-e', "$key = $value"]);
        }

        // Submission (587, STARTTLS obavezan) i SMTPS (465)
        Proc::mustRun(['postconf', '-M', 'submission/inet=submission inet n - y - - smtpd']);
        Proc::mustRun(['postconf', '-M', 'smtps/inet=smtps inet n - y - - smtpd']);
        foreach ([
            'submission/inet/smtpd_tls_security_level=encrypt',
            'submission/inet/smtpd_sasl_auth_enable=yes',
            'smtps/inet/smtpd_tls_wrappermode=yes',
            'smtps/inet/smtpd_sasl_auth_enable=yes',
        ] as $override) {
            Proc::mustRun(['postconf', '-P', $override]);
        }

        $check = Proc::run(['postfix', 'check']);
        if (!$check->ok()) {
            throw new \RuntimeException('postfix check pao: ' . trim($check->stderr));
        }
    }

    private function configureDovecot(string $mail_db_pass, \Closure $log): void
    {
        $log("Dovecot 2.4 konfiguracija (inline SQL auth, LMTP)\n");

        // Stari 2.3 SQL fajl ukloni ako je zaostao (2.4 ga ne koristi)
        @unlink('/etc/dovecot/dovecot-sql.conf.ext');

        $has_v6 = is_readable('/proc/net/if_inet6') && trim((string) file_get_contents('/proc/net/if_inet6')) !== '';
        $listen = $has_v6 ? '*, ::' : '*';

        // Dovecot 2.4: mail_location → mail_driver/mail_path/mail_home,
        // dovecot-sql.conf.ext ukinut (SQL inline), varijable %{user|...},
        // ssl_cert/ssl_key → ssl_server_cert_file/ssl_server_key_file.
        $conf = <<<CONF
        # ForgePanel mail (Dovecot 2.4) — virtualni mailboxi iz panel baze
        listen = {$listen}
        protocols = imap pop3 lmtp

        mail_uid = vmail
        mail_gid = vmail
        mail_privileged_group = vmail
        mail_home = /var/vmail/%{user | domain}/%{user | username}
        mail_driver = maildir
        mail_path = ~/Maildir

        ssl = yes
        ssl_server_cert_file = /etc/forgepanel/ssl/panel/fullchain.pem
        ssl_server_key_file = /etc/forgepanel/ssl/panel/privkey.pem

        auth_mechanisms = plain login
        auth_username_format = %{user | lower}

        sql_driver = mysql
        mysql /run/mysqld/mysqld.sock {
          user = forgepanel_mail
          password = {$mail_db_pass}
          dbname = forgepanel
        }

        passdb sql {
          query = SELECT CONCAT(m.local_part, '@', d.domain) AS user, m.password_hash AS password FROM mailboxes m JOIN mail_domains d ON d.id = m.mail_domain_id WHERE m.local_part = '%{user | username}' AND d.domain = '%{user | domain}' AND m.status = 'active'
          default_password_scheme = SHA512-CRYPT
        }
        userdb sql {
          query = SELECT 'vmail' AS uid, 'vmail' AS gid FROM mailboxes m JOIN mail_domains d ON d.id = m.mail_domain_id WHERE m.local_part = '%{user | username}' AND d.domain = '%{user | domain}' AND m.status = 'active'
        }

        service lmtp {
          unix_listener /var/spool/postfix/private/dovecot-lmtp {
            mode = 0600
            user = postfix
            group = postfix
          }
        }
        service auth {
          unix_listener /var/spool/postfix/private/auth {
            mode = 0660
            user = postfix
            group = postfix
          }
        }
        CONF;
        file_put_contents('/etc/dovecot/conf.d/99-forgepanel.conf', $conf . "\n");
        // sadrži DB lozinku → samo root + dovecot grupa
        @chgrp('dovecot', '/etc/dovecot/conf.d/99-forgepanel.conf');
        chmod('/etc/dovecot/conf.d/99-forgepanel.conf', 0o640);

        $check = Proc::run(['doveconf', '-n']);
        if (!$check->ok()) {
            throw new \RuntimeException('doveconf pao: ' . trim($check->stderr));
        }
    }

    private function configureRspamd(\Closure $log): void
    {
        $log("Rspamd (milter + DKIM signing)\n");
        file_put_contents('/etc/rspamd/local.d/dkim_signing.conf', <<<CONF
        path = "/var/lib/rspamd/dkim/\$domain.\$selector.key";
        selector = "forge";
        allow_username_mismatch = true;
        sign_authenticated = true;
        try_fallback = false;
        use_domain = "header";
        CONF . "\n");

        $check = Proc::run(['rspamadm', 'configtest']);
        if (!$check->ok()) {
            throw new \RuntimeException('rspamadm configtest pao: ' . trim($check->stdout ?: $check->stderr));
        }
    }

    /**
     * DKIM ključ za domenu; vraća sadržaj TXT zapisa za forge._domainkey.
     * @return array{selector: string, dkim_txt: string}
     */
    public function domainAddDkim(string $domain): array
    {
        $domain = Validator::fqdn($domain);
        if (!is_dir(self::DKIM_DIR)) {
            throw new ValidationException('Mail stack nije instaliran (mail.setup)');
        }
        $key_path = self::DKIM_DIR . "/{$domain}." . self::DKIM_SELECTOR . '.key';

        $result = Proc::mustRun([
            'rspamadm', 'dkim_keygen', '-s', self::DKIM_SELECTOR, '-d', $domain, '-b', '2048', '-k', $key_path,
        ]);
        Proc::mustRun(['chown', '_rspamd:_rspamd', $key_path]);
        chmod($key_path, 0o440);

        // stdout sadrži DNS TXT u BIND formatu — izvuci spojeni sadržaj quotanih stringova
        preg_match_all('/"([^"]*)"/', $result->stdout, $m);
        $dkim_txt = implode('', $m[1]);
        if ($dkim_txt === '' || !str_contains($dkim_txt, 'v=DKIM1')) {
            throw new \RuntimeException('dkim_keygen nije vratio TXT zapis');
        }

        return ['selector' => self::DKIM_SELECTOR, 'dkim_txt' => $dkim_txt];
    }

    public function domainRemoveDkim(string $domain): void
    {
        $domain = Validator::fqdn($domain);
        @unlink(self::DKIM_DIR . "/{$domain}." . self::DKIM_SELECTOR . '.key');
    }
}
