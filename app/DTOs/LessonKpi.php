<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LessonKpi
{
    public function __construct(
        public string $id,
        public string $label,
        public string $unit,
        public string $format,
        public string $better,
    ) {}
}
