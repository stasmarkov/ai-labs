<?php

declare(strict_types=1);

namespace App\Ai;

use RuntimeException;
use Throwable;

/**
 * Raised inside a fallback-guarded stream when the main model's first content event comes after the feature's budget;
 * it never leaves LocalFallback as the fallback takes over, and ends the stream with the masked error if that fails too.
 */
final class FirstTokenBudgetExceeded extends RuntimeException
{
    public function __construct(int $afterMs, ?Throwable $previous = null)
    {
        parent::__construct("No first token within {$afterMs} ms", 0, $previous);
    }
}
