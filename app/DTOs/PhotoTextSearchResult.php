<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoTextSearchResult
{
    /**
     * @param  list<SimilarPhotoMatch>  $results
     */
    public function __construct(
        public string $query,
        public string $prompt,
        public string $label,
        public float $minScore,
        public bool $cached,
        public array $results,
    ) {}
}
