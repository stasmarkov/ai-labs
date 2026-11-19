<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class Lesson
{
    /**
     * @param  list<array{id: string, text: string}>  $learn
     * @param  list<LessonNode>  $nodes
     * @param  list<LessonEdge>  $edges
     * @param  list<string>  $legend  the packet kinds the lesson uses
     * @param  list<LessonKpi>  $kpis
     * @param  list<LessonStep>  $steps
     */
    public function __construct(
        public string $module,
        public string $slug,
        public string $title,
        public string $summary,
        public int $minutes,
        public string $level,
        public array $learn,
        public ?string $guide,
        public array $nodes,
        public array $edges,
        public array $legend,
        public array $kpis,
        public array $steps,
    ) {}
}
