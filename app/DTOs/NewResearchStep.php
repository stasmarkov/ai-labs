<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ResearchStepPhase;
use App\Enums\ResearchStepStatus;

/**
 * One phase of a research run as it finishes. The repository gives it the next position in the run.
 */
final readonly class NewResearchStep
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>|null  $output
     */
    public function __construct(
        public ResearchStepPhase $phase,
        public array $input,
        public ?array $output,
        public ResearchStepStatus $status,
        public int $durationMs,
        public ?int $subQuestionIndex = null,
        public ?int $aiRunId = null,
        public ?string $error = null,
    ) {}
}
