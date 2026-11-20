<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Lesson;
use App\DTOs\LessonCourseEntry;
use App\DTOs\LessonStep;
use App\Guides\GuideRepository;
use App\Lessons\InvalidLessonException;
use App\Lessons\LessonService;
use App\Lessons\LessonTopics;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds the props of the course page and the lesson page from the resolved lesson DTOs: resolved data only, no source.
 */
final readonly class LessonPageService
{
    public function __construct(
        private LessonService $lessons,
        private LessonTopics $topics,
        private GuideRepository $guides,
    ) {}

    /**
     * @return array{course: array{modules: list<array<string, mixed>>}}
     */
    public function course(): array
    {
        return ['course' => $this->courseProps()];
    }

    /**
     * @return array{lesson: \Closure, step: int, course: \Closure, guides: list<array{key: string, title: string, summary: string, href: string}>}
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

        return ['lesson' => fn (): array => $this->lessonProps($lesson), 'step' => $number, 'course' => fn (): array => $this->courseProps(), 'guides' => $this->guideLinks($this->topics->guidesOfLesson($module, $slug))];
    }

    /**
     * The course with each lesson's guides and each module's reference topics; public so a guide page carries the same
     * course as the lesson pages.
     *
     * @return array{modules: list<array<string, mixed>>}
     */
    public function courseProps(): array
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
                    'guides' => $this->guideLinks($this->topics->guidesOfLesson($module->slug, $entry->slug)),
                ], $module->lessons),
                'references' => $this->guideLinks($module->references),
            ];
        }

        return ['modules' => $modules];
    }

    /**
     * What a guide page adds to its own data: its course topic and the course for the sidebar. A broken course file
     * costs the page that extra, never the guide itself.
     *
     * @return array{topic: array<string, mixed>|null, course: array{modules: list<array<string, mixed>>}}
     */
    public function guideContext(string $key): array
    {
        try {
            return ['topic' => $this->topic($key), 'course' => $this->courseProps()];
        } catch (InvalidLessonException $exception) {
            report($exception);

            return ['topic' => null, 'course' => ['modules' => []]];
        }
    }

    /**
     * The course topic a guide page belongs to: its module and the playable lessons that teach it (none for a
     * reference topic). Null for a guide the course does not own.
     *
     * @return array{module: array{slug: string, title: string}, lessons: list<array{key: string, title: string, href: string}>}|null
     */
    public function topic(string $key): ?array
    {
        $slug = $this->topics->moduleOfGuide($key);

        if ($slug === null) {
            return null;
        }

        foreach ($this->lessons->course()->modules as $module) {
            if ($module->slug === $slug) {
                return [
                    'module' => ['slug' => $module->slug, 'title' => $module->title],
                    'lessons' => $this->lessonLinks($this->guides->find($key)->lessons),
                ];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, title: string, href: string}>
     */
    private function lessonLinks(array $keys): array
    {
        return array_map(fn (array $lesson): array => [
            'key' => $lesson['module'].'/'.$lesson['slug'],
            'title' => $lesson['title'],
            'href' => route('ai.learn.show', ['module' => $lesson['module'], 'lesson' => $lesson['slug']], absolute: false),
        ], $this->lessons->titles($keys));
    }

    /**
     * @param  list<string>  $keys
     * @return list<array{key: string, title: string, summary: string, href: string}>
     */
    private function guideLinks(array $keys): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'title' => $this->guides->find($key)->title,
            'summary' => $this->guides->find($key)->summary,
            'href' => route('ai.guides.show', ['key' => $key], absolute: false),
        ], $keys);
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
