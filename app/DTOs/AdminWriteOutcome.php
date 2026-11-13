<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What applying a write tool came to. `outcome` is "confirmed" (written), "error" (refused: unknown tool, invalid at
 * confirm, or the tool failed) or "failed" (an unexpected exception); `reply` is what the proposer is told.
 */
final readonly class AdminWriteOutcome
{
    /**
     * @param  array<string, mixed>  $reply
     */
    public function __construct(
        public string $outcome,
        public ?string $error,
        public array $reply,
    ) {}
}
