<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class DocsCachePairException extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("Cache pair set not found: {$path}");
    }

    public static function atLine(int $line, string $problem): self
    {
        return new self("Cache pair set line {$line}: {$problem}");
    }

    public static function tooFew(string $label, int $found, int $required): self
    {
        return new self("Cache pair set needs at least {$required} {$label} pairs, found {$found}.");
    }
}
