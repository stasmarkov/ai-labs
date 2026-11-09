<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class NlSearchRefusedException extends RuntimeException
{
    public function __construct(public readonly string $pattern, public readonly string $invocationId)
    {
        parent::__construct('This query looks like an attempt to change the assistant\'s instructions, so it was not processed.');
    }
}
