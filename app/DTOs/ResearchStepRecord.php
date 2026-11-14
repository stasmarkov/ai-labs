<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ResearchStepPhase;
use App\Enums\ResearchStepStatus;
use Carbon\CarbonImmutable;

/**
 * A stored research step, as a replay shows it.
 */
final readonly class ResearchStepRecord
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $output
     */
    public function __construct(
        public int $id,
        public int $position,
        public ResearchStepPhase $phase,
        public ?int $subQuestionIndex,
        public array $input,
        public ?array $output,
        public ?int $aiRunId,
        public ResearchStepStatus $status,
        public ?string $error,
        public int $durationMs,
        public ?CarbonImmutable $createdAt,
    ) {}
}
