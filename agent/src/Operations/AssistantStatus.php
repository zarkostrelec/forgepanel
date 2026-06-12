<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\System\ClaudeCli;
use ForgePanel\Agent\TaskContext;

/** assistant.status — je li lokalni Claude CLI dostupan i prijavljen. */
final class AssistantStatus extends Operation
{
    public function validate(array $params): void
    {
    }

    public function execute(array $params, TaskContext $context): array
    {
        $s = (new ClaudeCli($this->config))->status();
        return ['available' => $s['available'], 'logged_in' => $s['logged_in']];
    }
}
