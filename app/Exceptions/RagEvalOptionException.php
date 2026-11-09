<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class RagEvalOptionException extends InvalidArgumentException
{
    public static function kTooLarge(int $k, int $depth): self
    {
        return new self("k={$k} is larger than ai.docs.rrf_depth={$depth}; a smaller k can only be cut exactly from a list that deep.");
    }

    public static function noKs(): self
    {
        return new self('At least one k is needed.');
    }

    public static function noModes(): self
    {
        return new self('At least one retrieval mode is needed.');
    }

    public static function badLabel(string $label): self
    {
        return new self("Invalid --label \"{$label}\": use 1 to 60 letters, digits, dots, dashes or underscores, and not \".\" or \"..\".");
    }

    public static function outputOutsideResults(string $path, string $root): self
    {
        return new self("Invalid --output-dir {$path}: result directories must be inside {$root}.");
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unknownModel(string $model, array $allowed): self
    {
        return new self("Unknown model \"{$model}\": use one of ".implode(', ', $allowed).'.');
    }
}
