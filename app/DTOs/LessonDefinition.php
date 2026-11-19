<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A lesson file after loading and validation, before anything is resolved: steps still hold their state patches,
 * number pointers and placeholders. LessonService turns it into a Lesson.
 */
final readonly class LessonDefinition
{
    /**
     * @param  list<array{id: string, text: string}>  $learn
     * @param  list<LessonNode>  $nodes
     * @param  list<LessonEdge>  $edges
     * @param  list<LessonKpi>  $kpis
     * @param  list<LessonStepDefinition>  $steps
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
        public array $kpis,
        public array $steps,
    ) {}
}
