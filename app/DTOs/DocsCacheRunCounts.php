<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What DocsAnswerAgent runs in a window say about the cache.
 */
final readonly class DocsCacheRunCounts
{
    /**
     * @param  array<string, int>  $hitsByKind  cached runs per cache kind
     * @param  list<int>  $savedLatenciesMs  of the cached runs, ascending
     */
    public function __construct(
        public int $completed,
        public array $hitsByKind,
        public array $savedLatenciesMs,
    ) {}
}
