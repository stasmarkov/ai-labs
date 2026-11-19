<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LessonCourseEntry
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public string $level,
        public bool $comingSoon,
        public bool $available = true,
        public ?int $minutes = null,
        public ?int $steps = null,
    ) {}
}
