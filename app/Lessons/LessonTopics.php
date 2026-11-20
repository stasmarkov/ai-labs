<?php

declare(strict_types=1);

namespace App\Lessons;

use App\Guides\GuideRepository;
use App\Guides\InvalidGuideException;

/**
 * Which guide belongs to which lesson and module, read from the course file and the guides' own lesson links only
 * (a light read of each guide's `lessons` key; a guide too broken for that is left out, the course already reported it).
 * Bound per request (scoped), so the map is built once.
 */
final class LessonTopics
{
    /** @var array<string, list<string>>|null */
    private ?array $guidesByLesson = null;

    /** @var array<string, string>|null */
    private ?array $moduleByGuide = null;

    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly GuideRepository $guides,
    ) {}

    /**
     * The keys of the guides that list a <module>/<lesson>.
     *
     * @return list<string>
     */
    public function guidesOfLesson(string $module, string $lesson): array
    {
        return $this->map()[0]["{$module}/{$lesson}"] ?? [];
    }

    /**
     * The slug of the module that owns a guide: the module that references it, or the module of the first course lesson
     * it lists. Null for a guide the course does not own (the one that explains the lessons).
     */
    public function moduleOfGuide(string $key): ?string
    {
        return $this->map()[1][$key] ?? null;
    }

    /**
     * @return array{array<string, list<string>>, array<string, string>}
     */
    private function map(): array
    {
        if ($this->guidesByLesson !== null && $this->moduleByGuide !== null) {
            return [$this->guidesByLesson, $this->moduleByGuide];
        }

        $this->guidesByLesson = [];
        $this->moduleByGuide = [];
        $listed = [];

        foreach ($this->lessons->course()->modules as $module) {
            foreach ($module->references as $key) {
                $this->moduleByGuide[$key] = $module->slug;
            }

            foreach ($module->lessons as $entry) {
                $listed["{$module->slug}/{$entry->slug}"] = $module->slug;
            }
        }

        foreach ($this->guides->keys() as $key) {
            try {
                $named = $this->guides->lessonsOf($key);
            } catch (InvalidGuideException) {
                continue;
            }

            foreach ($named as $lesson) {
                $this->guidesByLesson[$lesson][] = $key;

                if (! isset($this->moduleByGuide[$key]) && isset($listed[$lesson])) {
                    $this->moduleByGuide[$key] = $listed[$lesson];
                }
            }
        }

        return [$this->guidesByLesson, $this->moduleByGuide];
    }
}
