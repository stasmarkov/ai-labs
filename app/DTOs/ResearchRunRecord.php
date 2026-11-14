<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ResearchRunStatus;
use Carbon\CarbonImmutable;

/**
 * A stored research run with its steps in order, which is everything a replay needs.
 */
final readonly class ResearchRunRecord
{
    /** A run still `running` after this long was lost with its job: it is shown as failed and is not executed. */
    public const STALE_AFTER_MINUTES = 10;

    /**
     * @param  list<string>|null  $subQuestions
     * @param  list<array<string, mixed>>|null  $sources
     * @param  array<string, string>  $promptVersions
     * @param  list<ResearchStepRecord>  $steps
     */
    public function __construct(
        public string $id,
        public string $topic,
        public ResearchRunStatus $status,
        public ?array $subQuestions,
        public ?string $report,
        public ?array $sources,
        public int $modelCalls,
        public int $stepCount,
        public int $failedNotes,
        public int $droppedChunkIds,
        public int $unknownCitations,
        public int $tokensIn,
        public int $tokensOut,
        public string $provider,
        public string $model,
        public array $promptVersions,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $finishedAt,
        public ?string $error,
        public array $steps = [],
    ) {}

    public function isStale(): bool
    {
        return $this->status === ResearchRunStatus::Running && $this->startedAt !== null && $this->startedAt->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
