<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\AiToolStepRecord;

interface AiToolStepRepositoryInterface
{
    /**
     * Adds a step to a run that was already saved, for a decision made after the run ended, and updates the run's step
     * counters. Does nothing when the run is missing.
     */
    public function append(string $invocationId, AiToolStepRecord $step): void;
}
