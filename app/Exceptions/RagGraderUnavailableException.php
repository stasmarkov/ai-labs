<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RagGraderUnavailableException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("The grader model {$model} is unavailable: the first graded answer failed on both attempts. Check that it is pulled and the provider is up, or pass another --grader.");
    }
}
