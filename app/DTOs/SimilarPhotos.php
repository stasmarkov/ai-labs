<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SimilarPhotos
{
    /**
     * @param  list<SimilarPhotoMatch>  $matches
     */
    public function __construct(
        public array $matches,
        public ?string $reason,
        public string $label,
        public bool $cached,
    ) {}
}
