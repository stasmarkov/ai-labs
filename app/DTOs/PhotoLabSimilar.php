<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoLabSimilar
{
    /**
     * @param  list<array{photo_id: int, listing_id: int, title: string, url: string, href: string, similarity: float, same_make_model: bool}>  $matches
     */
    public function __construct(
        public array $matches,
        public ?string $reason,
        public string $label,
        public bool $cached,
        public float $minScore,
        public float $nearDuplicateCutoff,
    ) {}

    /**
     * @return array{label: string, reason: string|null, cached: bool, min_score: float, near_duplicate_cutoff: float, matches: list<array{photo_id: int, listing_id: int, title: string, url: string, href: string, similarity: float, same_make_model: bool}>}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'reason' => $this->reason,
            'cached' => $this->cached,
            'min_score' => $this->minScore,
            'near_duplicate_cutoff' => $this->nearDuplicateCutoff,
            'matches' => $this->matches,
        ];
    }
}
