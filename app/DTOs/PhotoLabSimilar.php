<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoLabSimilar
{
    /**
     * @param  list<array{photo_id: int, listing_id: int, title: string, url: string, href: string, similarity: float, same_make_model: bool, parts?: array{image: float, caption: float|null, image_only: bool}}>  $matches
     * @param  float|null  $w  the hybrid weight of the image similarity, null for the image-only strip
     */
    public function __construct(
        public array $matches,
        public ?string $reason,
        public string $label,
        public bool $cached,
        public float $minScore,
        public float $nearDuplicateCutoff,
        public ?float $w = null,
    ) {}

    /**
     * @return array{label: string, reason: string|null, cached: bool, min_score: float, near_duplicate_cutoff: float, matches: list<array{photo_id: int, listing_id: int, title: string, url: string, href: string, similarity: float, same_make_model: bool, parts?: array{image: float, caption: float|null, image_only: bool}}>}
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

    /**
     * The hybrid payload of the endpoint: the strip, the weight, and how many matches were scored with a caption.
     *
     * @return array{label: string, reason: string|null, cached: bool, min_score: float, near_duplicate_cutoff: float, matches: list<array<string, mixed>>, w: float|null, coverage: array{captioned: int, total: int}|null}
     */
    public function toHybridArray(): array
    {
        $captioned = count(array_filter($this->matches, fn (array $match): bool => isset($match['parts']) && ! $match['parts']['image_only']));

        return [
            ...$this->toArray(),
            'w' => $this->w,
            'coverage' => $this->w === null ? null : ['captioned' => $captioned, 'total' => count($this->matches)],
        ];
    }
}
