<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The measured data cannot support the requested calculation, as opposed to a bad command option.
 */
final class RagEvalDataException extends RuntimeException
{
    public static function noQuestions(string $kind): self
    {
        return new self("Calibration needs at least one {$kind} question with a measured similarity.");
    }
}
