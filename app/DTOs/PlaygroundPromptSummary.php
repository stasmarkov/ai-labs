<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

/**
 * A playground prompt as the list shows it.
 */
final readonly class PlaygroundPromptSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public int $latestVersion,
        public int $versionsCount,
        public ?CarbonImmutable $updatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'latest_version' => $this->latestVersion,
            'versions_count' => $this->versionsCount,
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
