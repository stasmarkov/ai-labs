<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The embeddings provider is configured with a vector size the vector columns cannot store.
 */
final class EmbeddingDimensionsException extends RuntimeException
{
    public static function forProvider(string $provider, int $dimensions, int $expected): self
    {
        return new self("The embeddings provider \"{$provider}\" is configured with {$dimensions} dimensions, but the vector columns hold {$expected}.");
    }
}
