<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * A write the model proposed and the developer has not decided yet. Nothing is written until the confirm endpoint
 * accepts its id. The run id is the model call that proposed it, where the decision's step row is logged too.
 */
final readonly class AdminPendingAction
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $preview
     */
    public function __construct(
        public string $id,
        public string $tool,
        public array $arguments,
        public string $toolCallId,
        public array $preview,
        public CarbonImmutable $expiresAt,
        public string $runId,
    ) {}

    public function expired(): bool
    {
        return $this->expiresAt->isPast();
    }

    /**
     * What the client may see of it.
     *
     * @return array{action_id: string, tool: string, arguments: array<string, mixed>, preview: array<string, mixed>, expires_at: string}
     */
    public function toArray(): array
    {
        return [
            'action_id' => $this->id,
            'tool' => $this->tool,
            'arguments' => $this->arguments,
            'preview' => $this->preview,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
