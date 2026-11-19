<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LessonEdge
{
    public function __construct(
        public string $id,
        public string $from,
        public string $to,
    ) {}
}
