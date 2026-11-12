<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AssistantEvalQuestion
{
    /**
     * @param  array<string, string|int>  $expectedFilters  the fields a correct first valid call must contain
     * @param  int  $maxStepsOk  the tool steps a good answer needs
     * @param  list<DocsChatTurn>  $history  earlier turns the question follows
     * @param  list<string>  $unsupported  words of which at least one must appear in the answer, because the question asks for something the search tool lacks
     */
    public function __construct(
        public string $id,
        public string $question,
        public bool $expectsTool,
        public array $expectedFilters,
        public int $maxStepsOk,
        public array $history = [],
        public array $unsupported = [],
    ) {}
}
