<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\Proc;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\Validator;

/** git.keygen — ed25519 deploy key per repo; privatni ključ nikad ne napušta server. */
final class GitKeygen extends Operation
{
    public const KEY_DIR = '/etc/forgepanel/git';

    public function validate(array $params): void
    {
        Validator::positiveInt($params['vhost_id'] ?? null, 'vhost_id');
    }

    public function execute(array $params, TaskContext $context): array
    {
        $vhost_id = (int) $params['vhost_id'];
        if (!is_dir(self::KEY_DIR)) {
            mkdir(self::KEY_DIR, 0o700, true);
        }
        $key_path = self::KEY_DIR . "/vh_{$vhost_id}_deploy";
        if (!is_file($key_path)) {
            Proc::mustRun(['ssh-keygen', '-t', 'ed25519', '-N', '', '-C', "forgepanel-vh-{$vhost_id}", '-f', $key_path]);
            chmod($key_path, 0o600);
        }
        return ['public_key' => trim((string) file_get_contents($key_path . '.pub'))];
    }
}
