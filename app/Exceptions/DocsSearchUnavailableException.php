<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class DocsSearchUnavailableException extends RuntimeException
{
    public static function because(Throwable $previous): self
    {
        return new self('Docs search is unavailable.', 0, $previous);
    }
}
