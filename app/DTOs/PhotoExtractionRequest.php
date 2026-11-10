<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoExtractionRequest
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $promptVersion,
    ) {}
}
