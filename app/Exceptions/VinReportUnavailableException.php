<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class VinReportUnavailableException extends RuntimeException
{
    public static function because(?Throwable $previous = null): self
    {
        return new self('VIN service unavailable.', 0, $previous);
    }
}
