<?php

declare(strict_types=1);

namespace App\Lessons;

use App\DTOs\Lesson;
use App\DTOs\LessonCourse;
use App\DTOs\LessonCourseEntry;
use App\DTOs\LessonCourseModule;
use App\DTOs\LessonDefinition;
use App\DTOs\LessonKpi;
use App\DTOs\LessonStep;
use App\DTOs\LessonStepDefinition;

/**
 * Turns validated lesson files into what a learner sees: it replays each step's patch onto the step before, reads every
 * number from its result file, colours a KPI by its trend and fills in the text placeholders. Only lessons the course
 * lists are playable. Bound per request (scoped), so a lesson is resolved once.
 */
final class LessonService
{
    /** @var array<string, Lesson> */
    private array $resolved = [];

    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly LessonNumbers $numbers,
    ) {}

    /**
     * The course with each playable lesson's length; a broken lesson is listed as unavailable instead of failing the page.
     */
    public function course(): LessonCourse
    {
        $modules = [];

        foreach ($this->lessons->course()->modules as $module) {
            $entries = [];

            foreach ($module->lessons as $entry) {
                $entries[] = $entry->comingSoon ? $entry : $this->entry($module->slug, $entry);
            }

            $modules[] = new LessonCourseModule($module->slug, $module->title, $module->summary, $entries);
        }

        return new LessonCourse($modules);
    }

    /**
     * Null when the course does not list the lesson as playable (unknown, only announced, or a draft file); a broken
     * lesson file or course throws.
     */
    public function find(string $module, string $lesson): ?Lesson
    {
        if (! $this->isPlayable($module, $lesson)) {
            return null;
        }

        return $this->resolved["{$module}/{$lesson}"] ??= $this->resolve($this->lessons->find($module, $lesson));
    }

    /**
     * Steps are 1-based; a step outside the lesson is null.
     */
    public function step(Lesson $lesson, int $number): ?LessonStep
    {
        return $lesson->steps[$number - 1] ?? null;
    }

    /**
     * The titles of the playable lessons among the given <module>/<lesson> keys, read from the course file only: no lesson
     * is built and no number is read.
     *
     * @param  list<string>  $keys
     * @return list<array{module: string, slug: string, title: string}>
     */
    public function titles(array $keys): array
    {
        $titles = [];

        foreach ($keys as $key) {
            [$module, $slug] = array_pad(explode('/', $key, 2), 2, '');
            $entry = $this->courseEntry($module, $slug);

            if ($entry !== null && ! $entry->comingSoon) {
                $titles[] = ['module' => $module, 'slug' => $slug, 'title' => $entry->title];
            }
        }

        return $titles;
    }

    private function isPlayable(string $module, string $lesson): bool
    {
        $entry = $this->courseEntry($module, $lesson);

        return $entry !== null && ! $entry->comingSoon && $this->lessons->has($module, $lesson);
    }

    private function courseEntry(string $module, string $lesson): ?LessonCourseEntry
    {
        foreach ($this->lessons->course()->modules as $listed) {
            if ($listed->slug !== $module) {
                continue;
            }

            foreach ($listed->lessons as $entry) {
                if ($entry->slug === $lesson) {
                    return $entry;
                }
            }
        }

        return null;
    }

    private function entry(string $module, LessonCourseEntry $entry): LessonCourseEntry
    {
        try {
            $lesson = $this->find($module, $entry->slug);
        } catch (InvalidLessonException) {
            $lesson = null;
        }

        if ($lesson === null) {
            return new LessonCourseEntry($entry->slug, $entry->title, $entry->summary, $entry->level, false, false);
        }

        return new LessonCourseEntry($entry->slug, $entry->title, $entry->summary, $entry->level, false, true, $lesson->minutes, count($lesson->steps));
    }

    private function resolve(LessonDefinition $definition): Lesson
    {
        $where = "Lesson [{$definition->module}/{$definition->slug}]";
        $state = $this->baseState($definition);
        $previous = [];
        $used = [];
        $steps = [];

        foreach ($definition->steps as $step) {
            $here = "{$where} step {$step->number}";
            $state = $this->applyState($state, $step);
            [$values, $previous] = $this->kpiValues($step, $definition->kpis, $previous, $here);

            foreach ($state['edges'] as $edge) {
                $used = [...$used, ...array_keys($edge['packets'])];
            }

            $steps[] = new LessonStep(
                number: $step->number,
                role: $step->role,
                title: $step->title,
                body: array_map(fn (string $paragraph): string => $this->text($paragraph, $values, $here), $step->body),
                callout: $step->callout === null ? null : ['kind' => $step->callout['kind'], 'text' => $this->text($step->callout['text'], $values, $here)],
                nodes: $state['nodes'],
                edges: $this->visibleEdges($state, $definition),
                kpis: $values,
                changed: array_map(fn (array $change): array => ['sign' => $change['sign'], 'text' => $this->text($change['text'], $values, $here)], $step->changed),
                learn: $step->learn,
                deeper: $step->deeper,
            );
        }

        return new Lesson(
            module: $definition->module,
            slug: $definition->slug,
            title: $definition->title,
            summary: $definition->summary,
            minutes: $definition->minutes,
            level: $definition->level,
            learn: $definition->learn,
            guide: $definition->guide,
            nodes: $definition->nodes,
            edges: $definition->edges,
            legend: array_values(array_intersect(LessonRepository::PACKET_KINDS, $used)),
            kpis: $definition->kpis,
            steps: $steps,
        );
    }

    /**
     * Everything ok, load 0, queue 0, edges visible, no packets.
     *
     * @return array{nodes: array<string, array{load: int, queue: int, status: string, badge: ?string}>, edges: array<string, array{visible: bool, packets: array<string, int>}>}
     */
    private function baseState(LessonDefinition $definition): array
    {
        $state = ['nodes' => [], 'edges' => []];

        foreach ($definition->nodes as $node) {
            $state['nodes'][$node->id] = ['load' => 0, 'queue' => 0, 'status' => 'ok', 'badge' => null];
        }

        foreach ($definition->edges as $edge) {
            $state['edges'][$edge->id] = ['visible' => true, 'packets' => []];
        }

        return $state;
    }

    /**
     * A step's patch on top of the step before; a packet rate of 0 removes that kind from the edge.
     *
     * @param  array{nodes: array<string, array{load: int, queue: int, status: string, badge: ?string}>, edges: array<string, array{visible: bool, packets: array<string, int>}>}  $state
     * @return array{nodes: array<string, array{load: int, queue: int, status: string, badge: ?string}>, edges: array<string, array{visible: bool, packets: array<string, int>}>}
     */
    private function applyState(array $state, LessonStepDefinition $step): array
    {
        foreach ($step->state['nodes'] ?? [] as $id => $change) {
            $state['nodes'][$id] = [...$state['nodes'][$id], ...$change];
        }

        foreach ($step->state['edges'] ?? [] as $id => $change) {
            if (isset($change['visible'])) {
                $state['edges'][$id]['visible'] = $change['visible'];
            }

            foreach ($change['packets'] ?? [] as $kind => $rate) {
                if ($rate === 0) {
                    unset($state['edges'][$id]['packets'][$kind]);
                } else {
                    $state['edges'][$id]['packets'][$kind] = $rate;
                }
            }
        }

        return $state;
    }

    /**
     * A hidden node takes its edges with it.
     *
     * @param  array{nodes: array<string, array{load: int, queue: int, status: string, badge: ?string}>, edges: array<string, array{visible: bool, packets: array<string, int>}>}  $state
     * @return array<string, array{visible: bool, packets: array<string, int>}>
     */
    private function visibleEdges(array $state, LessonDefinition $definition): array
    {
        $edges = $state['edges'];

        foreach ($definition->edges as $edge) {
            if ($state['nodes'][$edge->from]['status'] === 'hidden' || $state['nodes'][$edge->to]['status'] === 'hidden') {
                $edges[$edge->id]['visible'] = false;
            }
        }

        return $edges;
    }

    /**
     * Resolves a step's KPI values; one the step leaves out keeps the previous value (and so shows no trend).
     *
     * @param  list<LessonKpi>  $kpis
     * @param  array<string, array{raw: ?float, display: string}>  $previous
     * @return array{list<array{id: string, label: string, value: string, trend: string}>, array<string, array{raw: ?float, display: string}>}
     */
    private function kpiValues(LessonStepDefinition $step, array $kpis, array $previous, string $where): array
    {
        $values = [];
        $current = [];

        foreach ($kpis as $kpi) {
            $source = $step->kpis[$kpi->id] ?? null;
            $current[$kpi->id] = $source === null ? $previous[$kpi->id] : $this->kpiValue($source, $kpi, "{$where} kpi [{$kpi->id}]");
            $compared = $source !== null && ($source['trend'] ?? true);

            $values[] = [
                'id' => $kpi->id,
                'label' => $kpi->label,
                'value' => $current[$kpi->id]['display'],
                'trend' => $compared ? $this->trend($kpi->better, $previous[$kpi->id]['raw'] ?? null, $current[$kpi->id]['raw']) : 'neutral',
            ];
        }

        return [$values, $current];
    }

    /**
     * @param  array{literal: string}|array{file: string, path: string, scale: int|float, round: ?int, trend: bool}  $source
     * @return array{raw: ?float, display: string}
     */
    private function kpiValue(array $source, LessonKpi $kpi, string $where): array
    {
        if (isset($source['literal'])) {
            return ['raw' => null, 'display' => $source['literal']];
        }

        $raw = $this->numbers->read($source['file'], $source['path'], $where) * $source['scale'];
        $raw = $source['round'] === null ? $raw : round($raw, $source['round']);

        return ['raw' => $raw, 'display' => $this->numbers->format($raw, $kpi->format, $source['round'], $kpi->unit)];
    }

    /**
     * Better or worse than the step before, by the KPI's direction; a literal or an unchanged value is neutral.
     */
    private function trend(string $better, ?float $before, ?float $now): string
    {
        if ($better === 'none' || $before === null || $now === null || $before === $now) {
            return 'neutral';
        }

        return ($now < $before) === ($better === 'lower') ? 'better' : 'worse';
    }

    /**
     * Fills in {kpi:id} with the step's value and {num:file#path|format|round} from its result file; the repository has
     * already checked that every placeholder is well formed.
     *
     * @param  list<array{id: string, label: string, value: string, trend: string}>  $values
     */
    private function text(string $text, array $values, string $where): string
    {
        return preg_replace_callback('/\{(kpi|num):([^}]*)\}/', function (array $match) use ($values, $where): string {
            if ($match[1] === 'kpi') {
                return (string) collect($values)->firstWhere('id', $match[2])['value'];
            }

            [$reference, $format, $round] = array_pad(explode('|', $match[2]), 3, null);
            [$file, $path] = explode('#', (string) $reference, 2);

            return $this->numbers->format($this->numbers->read($file, $path, $where), $format ?? 'count', $round === null ? null : (int) $round);
        }, $text) ?? $text;
    }
}
