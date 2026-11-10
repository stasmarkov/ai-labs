<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DatasetPhoto
{
    public function __construct(
        public string $file,
        public string $className,
    ) {}
}
