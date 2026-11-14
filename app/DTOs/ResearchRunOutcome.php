<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ResearchRunStatus;

/**
 * How a research run ended: its final status, the report and numbered sources, and the counts kept on the run.
 */
final readonly class ResearchRunOutcome
{
    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<string>|null  $subQuestions
     */
    public function __construct(
        public ResearchRunStatus $status,
        public ?string $report = null,
        public array $sources = [],
        public ?array $subQuestions = null,
        public ?string $error = null,
        public int $modelCalls = 0,
        public int $failedNotes = 0,
        public int $droppedChunkIds = 0,
        public int $unknownCitations = 0,
        public int $tokensIn = 0,
        public int $tokensOut = 0,
    ) {}
}
