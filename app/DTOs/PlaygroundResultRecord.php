<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PlaygroundResultStatus;

/**
 * One target's result of a playground run, to be stored or as stored.
 */
final readonly class PlaygroundResultRecord
{
    public function __construct(
        public int $position,
        public string $provider,
        public string $model,
        public PlaygroundResultStatus $status,
        public ?string $output,
        public ?string $error,
        public ?int $tokensIn,
        public ?int $tokensOut,
        public ?int $ttftMs,
        public int $durationMs,
        public ?string $aiRunInvocationId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status->value,
            'output' => $this->output,
            'error' => $this->error,
            'tokens_in' => $this->tokensIn,
            'tokens_out' => $this->tokensOut,
            'ttft_ms' => $this->ttftMs,
            'duration_ms' => $this->durationMs,
            'ai_run_invocation_id' => $this->aiRunInvocationId,
        ];
    }
}
