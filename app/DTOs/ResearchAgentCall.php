<?php

declare(strict_types=1);

namespace App\DTOs;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * One model call of a research run: its response or the error it ended with, the `ai_runs` row logged for it and its time.
 */
final readonly class ResearchAgentCall
{
    public function __construct(
        public ?AgentResponse $response,
        public ?int $aiRunId,
        public int $durationMs,
        public ?string $error = null,
    ) {}

    /**
     * The structured output of the call, or null when it failed or the agent has no schema.
     *
     * @return array<string, mixed>|null
     */
    public function structured(): ?array
    {
        return $this->response instanceof StructuredAgentResponse ? $this->response->structured : null;
    }

    public function text(): ?string
    {
        return $this->response?->text;
    }
}
