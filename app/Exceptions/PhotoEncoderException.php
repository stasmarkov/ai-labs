<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The image encoder did not return usable vectors: unreachable, an error status, or an answer of the wrong shape.
 */
final class PhotoEncoderException extends RuntimeException
{
    public static function unreachable(string $provider): self
    {
        return new self("The image encoder \"{$provider}\" did not answer.");
    }

    public static function failed(string $provider, int $status): self
    {
        return new self("The image encoder \"{$provider}\" answered with status {$status}.");
    }

    public static function wrongCount(int $expected, int $actual): self
    {
        return new self("The image encoder returned {$actual} vectors for {$expected} images.");
    }

    public static function wrongDimensions(string $model, int $expected, int $actual): self
    {
        return new self("The image encoder model \"{$model}\" returned {$actual} dimensions; the vector column holds {$expected}.");
    }

    public static function notConfigured(string $provider): self
    {
        return new self("The provider \"{$provider}\" is not an image encoder.");
    }
}
