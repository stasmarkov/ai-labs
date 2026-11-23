<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class LlmBenchException extends RuntimeException
{
    public static function unreachable(): self
    {
        return new self('Ollama did not answer; check that it is running and that OLLAMA_URL points to it.');
    }

    public static function requestFailed(string $step, int $status): self
    {
        return new self("Ollama answered {$status} to {$step}.");
    }

    public static function noListing(int $id): self
    {
        return new self("Listing {$id} is needed for the describe prompt and does not exist.");
    }

    public static function fallbackModelMissing(string $model): self
    {
        return new self("The fallback model {$model} is not installed on the Ollama server. Run `ollama pull {$model}` on the host; the bench does not pull models.");
    }

    public static function evalFailed(string $feature, string $model): self
    {
        return new self("The {$feature} eval of {$model} did not finish and left no result.");
    }
}
