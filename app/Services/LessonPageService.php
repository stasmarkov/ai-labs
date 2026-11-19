<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Lesson;
use App\DTOs\LessonCourseEntry;
use App\DTOs\LessonStep;
use App\Lessons\InvalidLessonException;
use App\Lessons\LessonService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds the props of the course page and the lesson page from the resolved lesson DTOs: resolved data only, no source.
 */
final readonly class LessonPageService
{
    public function __construct(private LessonService $lessons) {}

    /**
     * @return array{course: array{modules: list<array<string, mixed>>}}
     */
    public function course(): array
    {
        return ['course' => $this->courseProps()];
    }

    /**
     * @return array{lesson: \Closure, step: int, course: \Closure}
     */
    public function show(string $module, string $slug, ?int $step): array
    {
        try {
            $lesson = $this->lessons->find($module, $slug);
        } catch (InvalidLessonException $exception) {
            report($exception);

            throw new HttpException(500, 'This lesson is broken.');
        }

        if ($lesson === null) {
            throw new NotFoundHttpException;
        }

        $number = $step ?? 1;

        if ($this->lessons->step($lesson, $number) === null) {
            throw new NotFoundHttpException;
        }

        return ['lesson' => fn (): array => $this->lessonProps($lesson), 'step' => $number, 'course' => fn (): array => $this->courseProps()];
    }

    /**
     * @return array{modules: list<array<string, mixed>>}
     */
    private function courseProps(): array
    {
        $modules = [];

        foreach ($this->lessons->course()->modules as $module) {
            $modules[] = [
                'slug' => $module->slug,
                'title' => $module->title,
                'summary' => $module->summary,
                'lessons' => array_map(fn (LessonCourseEntry $entry): array => [
                    'slug' => $entry->slug,
                    'title' => $entry->title,
                    'summary' => $entry->summary,
                    'level' => $entry->level,
                    'comingSoon' => $entry->comingSoon,
                    'available' => $entry->available,
                    'minutes' => $entry->minutes,
                    'steps' => $entry->steps,
                    'href' => $entry->comingSoon || ! $entry->available ? null : route('ai.learn.show', ['module' => $module->slug, 'lesson' => $entry->slug], absolute: false),
                ], $module->lessons),
            ];
        }

        return ['modules' => $modules];
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonProps(Lesson $lesson): array
    {
        return [
            'module' => $lesson->module,
            'slug' => $lesson->slug,
            'title' => $lesson->title,
            'summary' => $lesson->summary,
            'minutes' => $lesson->minutes,
            'level' => $lesson->level,
            'learn' => $lesson->learn,
            'guide' => $lesson->guide === null ? null : route('ai.guides.show', ['key' => $lesson->guide], absolute: false),
            'nodes' => array_map(get_object_vars(...), $lesson->nodes),
            'edges' => array_map(get_object_vars(...), $lesson->edges),
            'legend' => $lesson->legend,
            'kpis' => array_map(get_object_vars(...), $lesson->kpis),
            'steps' => array_map(fn (LessonStep $step): array => [...get_object_vars($step), 'deeper' => $step->deeper === null ? null : $this->deeperHref($step->deeper)], $lesson->steps),
        ];
    }

    private function deeperHref(string $deeper): string
    {
        [$key, $tab] = explode('#', $deeper, 2);

        return route('ai.guides.show', ['key' => $key], absolute: false).'#'.$tab;
    }
}
