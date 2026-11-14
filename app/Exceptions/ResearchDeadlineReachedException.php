<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A research run has too little of its time budget left for the next model call; the run ends `failed` with the error
 * `deadline_reached`.
 */
final class ResearchDeadlineReachedException extends RuntimeException
{
    public static function reached(): self
    {
        return new self('The research run reached its time budget.');
    }
}
