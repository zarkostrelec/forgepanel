<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;
use ForgePanel\Agent\Validator;

/**
 * apps.wp_install — one-click WordPress: skida core, generira wp-config
 * (DB + sigurnosni salts), instalira kroz WP REST bootstrap. Bez wp-clija
 * (skida se core tarball direktno), izvršava se kao vhost user.
 */
final class WpInstall extends Operation
{
    private const WP_TARBALL = 'https://wordpress.org/latest.tar.gz';

    public function isLongRunning(): bool
    {
        return true;
    }

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
        Validator::fqdn($params['domain'] ?? null);
        Validator::identifier($params['db_name'] ?? null, 'db_name');
        Validator::identifier($params['db_user'] ?? null, 'db_user', 32);
        if (!is_string($params['db_password'] ?? null) || strlen($params['db_password']) < 12) {
            throw new ValidationException('db_password mora imati barem 12 znakova');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        $vhost_id = (int) $params['vhost_id'];
        $domain = Validator::fqdn($params['domain']);
        $sys_user = 'vh_' . $vhost_id;
        $docroot = Validator::VHOST_ROOT . "/$domain/httpdocs";
        if (!is_dir($docroot)) {
            throw new ValidationException("Docroot ne postoji: $docroot");
        }

        $context->output('Skidam WordPress core');
        $tmp = sys_get_temp_dir() . '/wp-' . bin2hex(random_bytes(6)) . '.tar.gz';
        Proc::mustRun(['curl', '-fsSL', '-o', $tmp, self::WP_TARBALL], timeout_s: 300, on_line: $context->output(...));
        $context->progress(40);

        $context->output('Raspakiravam u docroot');
        Proc::mustRun(['tar', '-xzf', $tmp, '-C', $docroot, '--strip-components=1'], timeout_s: 300);
        unlink($tmp);
        $context->progress(60);

        $context->output('Generiram wp-config.php (DB + salts)');
        $sample = (string) file_get_contents("$docroot/wp-config-sample.php");
        $config = str_replace(
            ['database_name_here', 'username_here', 'password_here', 'localhost'],
            [
                $this->escSingle((string) $params['db_name']),
                $this->escSingle((string) $params['db_user']),
                $this->escSingle((string) $params['db_password']),
                'localhost',
            ],
            $sample
        );
        // Sigurnosni salts
        $salts = $this->generateSalts();
        $config = preg_replace('/define\(\s*\'(AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)\'.*?\);/', '', $config);
        $config = str_replace('/* Add any custom values', $salts . "\n/* Add any custom values", $config);
        file_put_contents("$docroot/wp-config.php", $config);

        Proc::mustRun(['chown', '-R', "$sys_user:$sys_user", $docroot]);
        $context->progress(90);

        // Zabilježi kao app instancu u settings (popis za apps modul)
        $this->db->run(
            "INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ['wp_instance_' . $vhost_id, json_encode(['domain' => $domain, 'installed_at' => date('c')])]
        );

        $context->progress(100);
        $context->output("WordPress raspakiran na $domain — dovrši instalaciju na https://$domain/wp-admin/install.php");
        return ['domain' => $domain, 'admin_url' => "https://$domain/wp-admin/"];
    }

    private function escSingle(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function generateSalts(): string
    {
        $keys = ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'];
        $chars = '!@#$%^&*()-_ []{}<>~`+=,.;:/?|abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $lines = [];
        foreach ($keys as $key) {
            $salt = '';
            for ($i = 0; $i < 64; $i++) {
                $salt .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $lines[] = sprintf("define('%s', '%s');", $key, str_replace(["'", '\\'], '', $salt));
        }
        return implode("\n", $lines);
    }
}
