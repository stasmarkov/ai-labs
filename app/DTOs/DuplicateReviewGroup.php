<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateReviewGroup
{
    /**
     * @param  list<DuplicateReviewPhoto>  $photos  by ascending photo id
     */
    public function __construct(
        public int $id,
        public bool $wasSplit,
        public array $photos,
        public ?DuplicateReviewJudgement $judgement,
        public ?DuplicateReviewMark $review,
    ) {}

    /**
     * @return array{id: int, wasSplit: bool, photos: list<array<string, mixed>>, judgement: array<string, mixed>|null, review: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'wasSplit' => $this->wasSplit,
            'photos' => array_map(fn (DuplicateReviewPhoto $photo): array => $photo->toArray(), $this->photos),
            'judgement' => $this->judgement?->toArray(),
            'review' => $this->review?->toArray(),
        ];
    }
}
