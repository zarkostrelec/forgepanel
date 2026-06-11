<?php

declare(strict_types=1);

namespace ForgePanel\Agent\Operations;

use ForgePanel\Agent\Config;
use ForgePanel\Agent\Db;
use ForgePanel\Agent\TaskContext;

abstract class Operation
{
    public function __construct(
        protected readonly Config $config,
        protected readonly Db $db,
    ) {
    }

    /** Dugotrajne operacije idu isključivo kroz task queue, ne kroz sinkroni socket poziv. */
    public function isLongRunning(): bool
    {
        return false;
    }

    /**
     * Validira parametre PRIJE izvršavanja. Baca ValidationException.
     * @param array<string, mixed> $params
     */
    abstract public function validate(array $params): void;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    abstract public function execute(array $params, TaskContext $context): array;
}
