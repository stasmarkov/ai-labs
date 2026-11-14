<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A playground prompt with its versions, newest first.
 */
final readonly class PlaygroundPromptRecord
{
    /**
     * @param  list<PlaygroundVersionRecord>  $versions
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $versions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'versions' => array_map(fn (PlaygroundVersionRecord $version): array => $version->toArray(), $this->versions),
        ];
    }
}
