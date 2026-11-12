<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A photo with the caption of its current extraction (null when it has none) and what its text vector was made from.
 */
final readonly class PhotoCaptionRow
{
    public function __construct(
        public int $photoId,
        public ?string $caption,
        public ?string $textHash,
        public ?string $textModel,
        public bool $hasTextVector,
    ) {}
}
