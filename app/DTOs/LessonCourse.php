<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LessonCourse
{
    /**
     * @param  list<LessonCourseModule>  $modules
     */
    public function __construct(public array $modules) {}
}
