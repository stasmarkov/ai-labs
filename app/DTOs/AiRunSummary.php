<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AiRunSummary
{
    public function __construct(
        public string $invocationId,
        public string $agent,
        public string $provider,
        public string $model,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?int $ttftMs,
        public int $latencyMs,
        public string $status,
        public string $createdAt,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'invocation_id' => $this->invocationId,
            'agent' => $this->agent,
            'provider' => $this->provider,
            'model' => $this->model,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'ttft_ms' => $this->ttftMs,
            'latency_ms' => $this->latencyMs,
            'status' => $this->status,
            'created_at' => $this->createdAt,
        ];
    }
}
