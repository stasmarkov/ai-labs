<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * A playground run as the history list shows it.
 */
final readonly class PlaygroundRunSummary
{
    /**
     * @param  list<string>  $models  as `provider:model`, in column order
     */
    public function __construct(
        public string $id,
        public string $versionLabel,
        public string $input,
        public array $models,
        public ?CarbonImmutable $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'version_label' => $this->versionLabel,
            'input' => $this->input,
            'models' => $this->models,
            'created_at' => $this->createdAt?->toIso8601String(),
        ];
    }
}
