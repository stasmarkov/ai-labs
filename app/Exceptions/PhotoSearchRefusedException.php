<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PhotoSearchRefusedException extends RuntimeException
{
    public const CODE = 'refused';

    public function __construct(public readonly string $pattern, public readonly string $invocationId)
    {
        parent::__construct('This query matched the input guard\'s injection patterns, so it was not sent to the photo encoder.');
    }
}
