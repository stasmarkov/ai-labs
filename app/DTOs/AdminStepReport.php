<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One row of the timeline the client shows: a tool step of the current request with what came back.
 */
final readonly class AdminStepReport
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|null  $result  what the model was told, capped; null when nothing ran
     */
    public function __construct(
        public int $position,
        public string $tool,
        public array $arguments,
        public string $outcome,
        public ?string $error,
        public ?array $result,
        public int $durationMs,
        public string $runId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'outcome' => $this->outcome,
            'error' => $this->error,
            'result' => $this->result,
            'duration_ms' => $this->durationMs,
            'run_id' => $this->runId,
        ];
    }
}
