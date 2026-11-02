<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvalidEmbeddingException extends RuntimeException
{
    public static function wrongLength(int $expected, int $actual): self
    {
        return new self("The model returned a vector of {$actual} numbers; expected {$expected}.");
    }

    public static function wrongCount(int $expected, int $actual): self
    {
        return new self("The model returned {$actual} vectors for {$expected} descriptions.");
    }
}
