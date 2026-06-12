<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;

/**
 * apps.phpmyadmin_install — phpMyAdmin u panelovom izoliranom stacku (/pma/ na :8443),
 * auth_type=signon (login isključivo kroz panelov signed one-time token). Isti postupak
 * kao installer (install_phpmyadmin), izvediv naknadno iz panela (repair / kasnija instalacija).
 */
final class PhpMyAdminInstall extends Operation
{
    private const PMA_DIR = '/opt/forgepanel/phpmyadmin';

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        // bez parametara
    }

    public function execute(array $params, TaskContext $context): array
    {
        if (!is_file(self::PMA_DIR . '/index.php')) {
            $context->output("Preuzimam phpMyAdmin (latest, phpmyadmin.net)\n");
            $context->progress(15);
            $tarball = '/tmp/forgepanel-pma.tar.gz';
            Proc::mustRun(['curl', '-fsSL', '--retry', '3', '-o', $tarball,
                'https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.tar.gz'], timeout_s: 300);
            if (!is_dir('/opt/forgepanel')) {
                mkdir('/opt/forgepanel', 0o755, true);
            }
            $tmp = '/tmp/forgepanel-pma-' . bin2hex(random_bytes(4));
            mkdir($tmp, 0o700, true);
            $context->output("Raspakiravam\n");
            $context->progress(45);
            Proc::mustRun(['tar', '-xzf', $tarball, '-C', $tmp]);
            $extracted = glob($tmp . '/phpMyAdmin-*')[0] ?? null;
            if ($extracted === null || !is_dir($extracted)) {
                throw new \RuntimeException('phpMyAdmin arhiva neispravna');
            }
            Proc::run(['rm', '-rf', self::PMA_DIR]);
            Proc::mustRun(['mv', $extracted, self::PMA_DIR]);
            Proc::run(['rm', '-rf', $tmp, $tarball]);
        }

        $context->output("Konfiguracija (signon)\n");
        $context->progress(80);
        $blowfish = substr(base64_encode(random_bytes(24)), 0, 32);
        $config = <<<PHP
        <?php
        /* ForgePanel — phpMyAdmin signon konfiguracija (NE uređivati ručno) */
        declare(strict_types=1);
        \$cfg['blowfish_secret'] = '{$blowfish}';
        \$cfg['Servers'][1]['auth_type'] = 'signon';
        \$cfg['Servers'][1]['SignonSession'] = 'FPpmaSignon';
        \$cfg['Servers'][1]['SignonURL'] = '/pma-signon.php';
        \$cfg['Servers'][1]['host'] = 'localhost';
        \$cfg['Servers'][1]['AllowNoPassword'] = false;
        \$cfg['AllowArbitraryServer'] = false;
        \$cfg['PmaAbsoluteUri'] = '/pma/';
        \$cfg['TempDir'] = '/opt/forgepanel/phpmyadmin/tmp';
        \$cfg['ShowPhpInfo'] = false;
        \$cfg['VersionCheck'] = false;
        PHP;
        file_put_contents(self::PMA_DIR . '/config.inc.php', $config . "\n");
        if (!is_dir(self::PMA_DIR . '/tmp')) {
            mkdir(self::PMA_DIR . '/tmp', 0o700, true);
        }
        Proc::run(['chown', '-R', 'fpanel:fpanel', self::PMA_DIR . '/tmp']);
        chmod(self::PMA_DIR . '/tmp', 0o700);
        Proc::run(['chown', 'root:fpanel', self::PMA_DIR . '/config.inc.php']);
        chmod(self::PMA_DIR . '/config.inc.php', 0o640);

        $this->db->run(
            "INSERT INTO components (name, status, packages) VALUES ('phpmyadmin', 'installed', '[]')
             ON DUPLICATE KEY UPDATE status = 'installed'"
        );
        $context->progress(100);
        $context->output("phpMyAdmin spreman na /pma/ (auto-login iz panela)\n");
        return ['installed' => true];
    }
}
