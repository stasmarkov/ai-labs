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
}
