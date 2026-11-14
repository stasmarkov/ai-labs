<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A research run would pass its cap on model calls or steps; the run ends `failed` with the error `cap_reached`.
 */
final class ResearchCapReachedException extends RuntimeException
{
    public static function for(string $cap): self
    {
        return new self("The research run reached its cap on {$cap}.");
    }
}
