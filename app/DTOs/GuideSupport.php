<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuideSupport
{
    public function __construct(
        public string $name,
        public string $status,
        public string $note,
    ) {}
}
