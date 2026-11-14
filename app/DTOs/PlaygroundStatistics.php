<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What the stored playground runs, prompts and versions add up to. The two-model local figure covers the runs of exactly
 * two targets that both ended ok on a local provider, and is the sum of the two call times of a run, averaged.
 */
final readonly class PlaygroundStatistics
{
    public function __construct(
        public int $runs,
        public float $averageModelsPerRun,
        public int $prompts,
        public int $versions,
        public int $twoModelLocalRuns,
        public int $averageTwoModelLocalDurationMs,
    ) {}
}
