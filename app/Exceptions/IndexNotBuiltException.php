<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The index holds vectors from another embedding model than the configured one: searching would compare unrelated vectors, HTTP 409.
 */
final class IndexNotBuiltException extends RuntimeException
{
    public const CODE = 'index_not_built';

    public static function forModel(string $model, string $command): self
    {
        return new self("No vectors were built with the embedding model \"{$model}\". Run {$command} to re-index.");
    }
}
