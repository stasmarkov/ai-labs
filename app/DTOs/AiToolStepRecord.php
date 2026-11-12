<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * One tool call of an assistant run, as logged: the arguments the model sent and a summary of the result, never the
 * result itself.
 */
final readonly class AiToolStepRecord
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  string  $outcome  "ok", "error" (the tool answered with an error) or "failed" (the tool threw)
     * @param  array<string, mixed>|null  $resultSummary
     */
    public function __construct(
        public int $position,
        public string $tool,
        public array $arguments,
        public string $outcome,
        public ?string $error,
        public ?array $resultSummary,
        public int $durationMs,
    ) {}
}
