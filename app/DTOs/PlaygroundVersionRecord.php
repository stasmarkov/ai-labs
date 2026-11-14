<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * A stored, immutable version of a playground prompt.
 */
final readonly class PlaygroundVersionRecord
{
    public function __construct(
        public int $id,
        public int $promptId,
        public string $promptName,
        public int $version,
        public string $systemPrompt,
        public ?string $note,
        public float $temperature,
        public int $maxTokens,
        public ?CarbonImmutable $createdAt,
    ) {}

    /**
     * The label a run of this version carries in the run log.
     */
    public function label(): string
    {
        return "playground:{$this->promptName}@v{$this->version}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'system_prompt' => $this->systemPrompt,
            'note' => $this->note,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'created_at' => $this->createdAt?->toIso8601String(),
        ];
    }
}
