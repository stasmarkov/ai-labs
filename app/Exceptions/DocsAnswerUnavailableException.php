<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class DocsAnswerUnavailableException extends RuntimeException
{
    public static function because(Throwable $previous): self
    {
        return new self('Docs answering is unavailable right now.', 0, $previous);
    }
}
