<?php

declare(strict_types=1);

namespace ForgePanel\Agent\System;

use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * ProFTPD virtualni FTP useri: AuthUserFile, vezani na vhost path
 * (uid/gid vhost sistemskog usera), TLS obavezan, chroot u home.
 */
final class ProftpdConf
{
    public const PASSWD_FILE = '/etc/proftpd/forgepanel.passwd';
    public const CONF_FILE = '/etc/proftpd/conf.d/forgepanel.conf';

    public static function ensureInstalled(?\Closure $log = null): void
    {
        if (!is_dir('/etc/proftpd')) {
            $log?->__invoke("Instaliram ProFTPD\n");
            Apt::install(['proftpd-basic'], $log);
        }
        if (is_file(self::CONF_FILE)) {
            return;
        }

        $conf = <<<'CONF'
        # ForgePanel FTP — virtualni useri, TLS obavezan, chroot u home
        DefaultRoot ~
        RequireValidShell off
        AuthOrder mod_auth_file.c
        AuthUserFile /etc/proftpd/forgepanel.passwd
        PassivePorts 49152 50192

        <IfModule mod_tls.c>
            TLSEngine on
            TLSRequired on
            TLSRSACertificateFile /etc/forgepanel/ssl/panel/fullchain.pem
            TLSRSACertificateKeyFile /etc/forgepanel/ssl/panel/privkey.pem
            TLSOptions NoSessionReuseRequired
        </IfModule>
        CONF;
        file_put_contents(self::CONF_FILE, $conf . "\n");

        if (!is_file(self::PASSWD_FILE)) {
            file_put_contents(self::PASSWD_FILE, '');
            chmod(self::PASSWD_FILE, 0o440);
        }

        $test = Proc::run(['proftpd', '-t']);
        if (!$test->ok()) {
            unlink(self::CONF_FILE);
            throw new \RuntimeException('proftpd -t pao, config uklonjen: ' . trim($test->stderr));
        }
        Systemd::enableNow('proftpd');
    }

    /**
     * Regenerira cijeli passwd file iz zadane liste (jedno mjesto istine = panel DB).
     * @param list<array{username: string, password_hash: string, sys_user: string, home: string}> $users
     */
    public static function syncUsers(array $users): int
    {
        $lines = [];
        foreach ($users as $user) {
            $username = (string) $user['username'];
            if (!preg_match('/^[a-z][a-z0-9_.-]{2,31}$/', $username)) {
                throw new ValidationException("Neispravno FTP korisničko ime: $username");
            }
            $hash = (string) $user['password_hash'];
            if (!str_starts_with($hash, '$6$') && !str_starts_with($hash, '$2y$')) {
                throw new ValidationException('password_hash mora biti crypt SHA-512 ili bcrypt');
            }
            $home = Validator::vhostPath((string) $user['home'], 'home');
            $sys_user = Validator::identifier($user['sys_user'], 'sys_user');
            $pw = posix_getpwnam($sys_user);
            if ($pw === false) {
                throw new ValidationException("Sistemski user ne postoji: $sys_user");
            }
            if (!is_dir($home)) {
                mkdir($home, 0o755, true);
                chown($home, $sys_user);
                chgrp($home, $sys_user);
            }
            $lines[] = sprintf('%s:%s:%d:%d:ForgePanel FTP:%s:/bin/false', $username, $hash, $pw['uid'], $pw['gid'], $home);
        }

        $tmp = self::PASSWD_FILE . '.new';
        file_put_contents($tmp, implode("\n", $lines) . ($lines === [] ? '' : "\n"));
        chmod($tmp, 0o440);
        rename($tmp, self::PASSWD_FILE);

        return count($lines);
    }
}
