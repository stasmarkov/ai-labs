<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateReviewPhoto
{
    public function __construct(
        public int $id,
        public string $url,
        public string $title,
        public float $similarity,
        public ?string $variant,
        public string $source,
        public ?string $reviewStatus,
    ) {}

    /**
     * @return array{id: int, url: string, title: string, similarity: float, variant: string|null, source: string, reviewStatus: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'title' => $this->title,
            'similarity' => $this->similarity,
            'variant' => $this->variant,
            'source' => $this->source,
            'reviewStatus' => $this->reviewStatus,
        ];
    }
}
