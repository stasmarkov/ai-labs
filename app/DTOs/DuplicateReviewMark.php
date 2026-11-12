<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateReviewMark
{
    public function __construct(
        public int $keptPhotoId,
        public string $source,
        public string $reviewedAt,
    ) {}

    /**
     * @return array{keptPhotoId: int, source: string, reviewedAt: string}
     */
    public function toArray(): array
    {
        return ['keptPhotoId' => $this->keptPhotoId, 'source' => $this->source, 'reviewedAt' => $this->reviewedAt];
    }
}
