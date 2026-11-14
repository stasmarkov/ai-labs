<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * A stored playground run with its results in order, which is everything a reopened comparison needs.
 */
final readonly class PlaygroundRunRecord
{
    /**
     * @param  list<string>  $inputFlags
     * @param  list<PlaygroundResultRecord>  $results
     */
    public function __construct(
        public string $id,
        public ?int $promptVersionId,
        public string $versionLabel,
        public string $systemPrompt,
        public string $input,
        public array $inputFlags,
        public float $temperature,
        public int $maxTokens,
        public ?CarbonImmutable $createdAt,
        public array $results,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'prompt_version_id' => $this->promptVersionId,
            'version_label' => $this->versionLabel,
            'system_prompt' => $this->systemPrompt,
            'input' => $this->input,
            'input_flags' => $this->inputFlags,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'created_at' => $this->createdAt?->toIso8601String(),
            'results' => array_map(fn (PlaygroundResultRecord $result): array => $result->toArray(), $this->results),
        ];
    }
}
