<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LessonNode
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public int $x,
        public int $y,
    ) {}
}
