<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class PhotoSearchUnavailableException extends RuntimeException
{
    public const CODE = 'encoder_unavailable';

    public static function because(Throwable $previous): self
    {
        return new self('Photo search is unavailable right now.', 0, $previous);
    }
}
