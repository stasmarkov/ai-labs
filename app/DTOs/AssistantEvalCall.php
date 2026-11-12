<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One tool call of an eval turn: what the model sent and what the tool answered.
 */
final readonly class AssistantEvalCall
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const OTHER = 'other';

    /**
     * @param  array<string, mixed>  $arguments
     * @param  string  $outcome  "ok", "invalid" (the arguments failed the rules) or "other" (final error, failure)
     * @param  list<int>  $returnedIds  the listing ids the tool returned
     */
    public function __construct(
        public array $arguments,
        public string $outcome,
        public array $returnedIds = [],
    ) {}
}
