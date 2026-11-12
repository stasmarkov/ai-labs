<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A photo with a vector as the duplicate eval sees it: the original it belongs to (itself for an imported photo) and,
 * for a generated copy, the kind of degradation.
 */
final readonly class DuplicateEvalPhoto
{
    public function __construct(
        public int $id,
        public int $originalId,
        public ?string $variant,
        public string $path,
        public int $width,
        public int $height,
    ) {}

    public function isOriginal(): bool
    {
        return $this->variant === null;
    }
}
