<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoExtractionTarget
{
    public function __construct(
        public int $extractionId,
        public string $photoPath,
        public string $provider,
        public string $model,
        public string $promptVersion,
    ) {}
}
