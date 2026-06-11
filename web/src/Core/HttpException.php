<?php

declare(strict_types=1);

namespace ForgePanel\Web\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $error)
    {
        parent::__construct($error);
    }
}
