<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The outcome of one eval question: the tool calls in order, the answer text and how long the turn took.
 */
final readonly class AssistantEvalTurn
{
    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /**
     * @param  list<AssistantEvalCall>  $calls
     */
    public function __construct(
        public string $status,
        public array $calls,
        public string $answer,
        public int $latencyMs,
    ) {}
}
