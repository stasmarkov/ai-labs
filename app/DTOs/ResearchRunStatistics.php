<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * What the stored research runs add up to. The averages are over the runs that executed (done or failed); a refused
 * run makes no model call and a running one is not over.
 */
final readonly class ResearchRunStatistics
{
    /**
     * @param  list<string>  $models  the models the executed runs used
     */
    public function __construct(
        public int $runs,
        public int $done,
        public int $failed,
        public int $refused,
        public int $running,
        public float $averageModelCalls,
        public int $maxModelCalls,
        public float $averageSteps,
        public int $averageDurationMs,
        public array $models,
    ) {}
}
