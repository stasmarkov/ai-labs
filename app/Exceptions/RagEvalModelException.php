<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A model the evaluation needs is unavailable, so the run stops without result files.
 */
final class RagEvalModelException extends RuntimeException
{
    public static function embeddingUnavailable(string $model, Throwable $previous): self
    {
        $cause = $previous;

        while ($cause->getPrevious() !== null) {
            $cause = $cause->getPrevious();
        }

        return new self("Embedding model {$model} is unavailable: {$cause->getMessage()}", 0, $previous);
    }

    public static function answerModelFailed(string $model): self
    {
        return new self("Answer model {$model} failed for every question in the first run. Check that it is pulled and the provider is up, or pass another --models entry.");
    }
}
