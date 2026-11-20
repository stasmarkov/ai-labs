<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LessonCourseModule
{
    /**
     * @param  list<LessonCourseEntry>  $lessons
     * @param  list<string>  $references  guide keys taught as reference-only topics of this module
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public array $lessons,
        public array $references = [],
    ) {}
}
