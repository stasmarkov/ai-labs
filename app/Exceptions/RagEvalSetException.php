<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RagEvalSetException extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("Eval set not found: {$path}");
    }

    public static function atLine(int $line, string $problem): self
    {
        return new self("Eval set line {$line}: {$problem}");
    }
}
