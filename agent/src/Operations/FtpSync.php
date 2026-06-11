<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\ProftpdConf;
use ForgePanel\Agent\TaskContext;
use ForgePanel\Agent\ValidationException;

/** ftp.sync — regenerira sve virtualne FTP usere iz panel baze (jedno mjesto istine). */
final class FtpSync extends Operation
{
    public function validate(array $params): void
    {
        if (!is_array($params['users'] ?? null)) {
            throw new ValidationException('users mora biti lista');
        }
    }

    public function execute(array $params, TaskContext $context): array
    {
        ProftpdConf::ensureInstalled($context->output(...));
        $count = ProftpdConf::syncUsers($params['users']);
        return ['synced' => $count];
    }
}
