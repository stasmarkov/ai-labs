<?php

declare(strict_types=1);

namespace App\Lessons;

use App\DTOs\LessonCourse;
use App\DTOs\LessonCourseEntry;
use App\DTOs\LessonCourseModule;
use App\DTOs\LessonDefinition;
use App\DTOs\LessonEdge;
use App\DTOs\LessonKpi;
use App\DTOs\LessonNode;
use App\DTOs\LessonStepDefinition;
use App\Guides\GuideRepository;
use App\Guides\InvalidGuideException;
use App\Services\GuidePageService;

/**
 * Loads and validates the lesson files and the course; resolving numbers and replaying the steps is LessonService's
 * job. Bound per request (scoped), so a file is parsed once.
 */
final class LessonRepository
{
    public const NODE_KINDS = ['browser', 'app', 'threads', 'workers', 'queue', 'model', 'vector-db', 'cache', 'cloud', 'users'];

    public const NODE_STATUSES = ['ok', 'busy', 'down', 'hidden'];

    public const PACKET_KINDS = ['request', 'token', 'tool', 'embedding', 'cache-hit', 'failure', 'health'];

    public const ROLES = ['problem', 'solution', 'careful', 'useful', 'summary'];

    public const CALLOUT_KINDS = ['careful', 'useful', 'key-idea'];

    public const LEVELS = ['beginner', 'intermediate', 'advanced'];

    public const SIGNS = ['+', '~', '-'];

    public const BETTER = ['lower', 'higher', 'none'];

    public const STEPS = [8, 13];

    public const OUTCOMES = [3, 6];

    public const WIDTH = 1200;

    public const HEIGHT = 600;

    public const MAX_RATE = 5;

    /** Tab names from before the guide page grouped its tabs, mapped to the tab that now holds that content. */
    private const RENAMED_TABS = ['failures' => 'support', 'recipes' => 'modify'];

    /** @var array<string, LessonDefinition> */
    private array $loaded = [];

    private ?LessonCourse $course = null;

    public function __construct(
        private readonly string $directory,
        private readonly GuideRepository $guides,
    ) {}

    public function has(string $module, string $lesson): bool
    {
        return $this->slug($module) && $this->slug($lesson) && is_file($this->path($module, $lesson));
    }

    public function find(string $module, string $lesson): LessonDefinition
    {
        $key = "{$module}/{$lesson}";

        if (! $this->has($module, $lesson)) {
            throw new InvalidLessonException("Unknown lesson [{$key}].");
        }

        return $this->loaded[$key] ??= $this->load($module, $lesson);
    }

    public function course(): LessonCourse
    {
        return $this->course ??= $this->loadCourse();
    }

    private function load(string $module, string $lesson): LessonDefinition
    {
        $data = require $this->path($module, $lesson);
        $where = "Lesson [{$module}/{$lesson}]";

        if (! is_array($data)) {
            throw new InvalidLessonException("{$where} must return an array.");
        }

        return $this->build($module, $lesson, $data, $where);
    }

    private function loadCourse(): LessonCourse
    {
        $file = $this->directory.'/course.php';
        $where = 'Course';

        if (! is_file($file)) {
            throw new InvalidLessonException("{$where}: course.php was not found.");
        }

        $data = require $file;

        if (! is_array($data)) {
            throw new InvalidLessonException("{$where} must return an array.");
        }

        $modules = [];
        $moduleSlugs = [];

        foreach ($this->list($data, 'modules', $where) as $row) {
            $slug = $this->slugField($row, 'slug', $where);
            $here = "{$where} module [{$slug}]";

            if (in_array($slug, $moduleSlugs, true)) {
                throw new InvalidLessonException("{$where}: module [{$slug}] is listed twice.");
            }
            $moduleSlugs[] = $slug;

            $entries = [];
            $lessonSlugs = [];

            foreach ($this->list($row, 'lessons', $here) as $entry) {
                $lessonSlug = $this->slugField($entry, 'slug', $here);
                $there = "{$here} lesson [{$lessonSlug}]";
                $soon = ($entry['coming_soon'] ?? false) === true;

                if (in_array($lessonSlug, $lessonSlugs, true)) {
                    throw new InvalidLessonException("{$here}: lesson [{$lessonSlug}] is listed twice.");
                }
                $lessonSlugs[] = $lessonSlug;

                if ($soon === $this->has($slug, $lessonSlug)) {
                    throw new InvalidLessonException($soon
                        ? "{$there} is coming soon but has a lesson file."
                        : "{$there} has no lesson file; mark it coming_soon or write it.");
                }

                $entries[] = new LessonCourseEntry(
                    $lessonSlug,
                    $this->string($entry, 'title', $there),
                    $this->string($entry, 'summary', $there),
                    $this->enum($entry, 'level', self::LEVELS, $there),
                    $soon,
                );
            }

            $modules[] = new LessonCourseModule($slug, $this->string($row, 'title', $here), $this->string($row, 'summary', $here), $entries);
        }

        return new LessonCourse($modules);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function build(string $module, string $slug, array $data, string $where): LessonDefinition
    {
        $learn = $this->outcomes($data, $where);
        $nodes = $this->nodes($data, $where);
        $edges = $this->edges($data, array_map(fn (LessonNode $node): string => $node->id, $nodes), $where);
        $kpis = $this->kpiDefinitions($data, $where);
        $guide = $this->guide($data, $where);
        $steps = $this->steps($data, $learn, $nodes, $edges, $kpis, $where);

        return new LessonDefinition(
            module: $module,
            slug: $slug,
            title: $this->string($data, 'title', $where),
            summary: $this->string($data, 'summary', $where),
            minutes: $this->integer($data, 'minutes', 1, 240, $where),
            level: $this->enum($data, 'level', self::LEVELS, $where),
            learn: $learn,
            guide: $guide,
            nodes: $nodes,
            edges: $edges,
            kpis: $kpis,
            steps: $steps,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{id: string, text: string}>
     */
    private function outcomes(array $data, string $where): array
    {
        $rows = $this->list($data, 'learn', $where);

        if (count($rows) < self::OUTCOMES[0] || count($rows) > self::OUTCOMES[1]) {
            throw new InvalidLessonException("{$where}: [learn] needs ".self::OUTCOMES[0].' to '.self::OUTCOMES[1].' outcomes, not '.count($rows).'.');
        }

        $outcomes = [];
        foreach ($rows as $row) {
            $id = $this->string($row, 'id', "{$where} outcome");

            if (preg_match('/^lo-[1-6]$/', $id) !== 1) {
                throw new InvalidLessonException("{$where}: outcome id [{$id}] must be lo-1 to lo-6.");
            }

            if (in_array($id, array_column($outcomes, 'id'), true)) {
                throw new InvalidLessonException("{$where}: outcome [{$id}] is listed twice.");
            }

            $outcomes[] = ['id' => $id, 'text' => $this->string($row, 'text', "{$where} outcome [{$id}]")];
        }

        return $outcomes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<LessonNode>
     */
    private function nodes(array $data, string $where): array
    {
        $scene = $this->map($data, 'scene', $where);
        $nodes = [];

        foreach ($this->list($scene, 'nodes', "{$where} scene") as $row) {
            $id = $this->string($row, 'id', "{$where} node");
            $here = "{$where} node [{$id}]";

            if (in_array($id, array_map(fn (LessonNode $node): string => $node->id, $nodes), true)) {
                throw new InvalidLessonException("{$here}: id is used twice.");
            }

            $nodes[] = new LessonNode(
                $id,
                $this->enum($row, 'kind', self::NODE_KINDS, $here),
                $this->string($row, 'label', $here),
                $this->integer($row, 'x', 0, self::WIDTH, $here),
                $this->integer($row, 'y', 0, self::HEIGHT, $here),
            );
        }

        if ($nodes === []) {
            throw new InvalidLessonException("{$where}: the scene needs at least one node.");
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $nodeIds
     * @return list<LessonEdge>
     */
    private function edges(array $data, array $nodeIds, string $where): array
    {
        $scene = $this->map($data, 'scene', $where);
        $edges = [];
        $ids = [];

        foreach ($this->list($scene, 'edges', "{$where} scene") as $row) {
            $id = $this->string($row, 'id', "{$where} edge");
            $here = "{$where} edge [{$id}]";

            if (in_array($id, $ids, true)) {
                throw new InvalidLessonException("{$here}: id is used twice.");
            }
            $ids[] = $id;

            $from = $this->string($row, 'from', $here);
            $to = $this->string($row, 'to', $here);

            foreach ([$from, $to] as $end) {
                if (! in_array($end, $nodeIds, true)) {
                    throw new InvalidLessonException("{$here}: points to unknown node [{$end}].");
                }
            }

            $edges[] = new LessonEdge($id, $from, $to);
        }

        return $edges;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<LessonKpi>
     */
    private function kpiDefinitions(array $data, string $where): array
    {
        $kpis = [];

        foreach ($this->list($data, 'kpis', $where) as $row) {
            $id = $this->string($row, 'id', "{$where} kpi");
            $here = "{$where} kpi [{$id}]";

            if (in_array($id, array_map(fn (LessonKpi $kpi): string => $kpi->id, $kpis), true)) {
                throw new InvalidLessonException("{$here}: id is used twice.");
            }

            $kpis[] = new LessonKpi(
                $id,
                $this->string($row, 'label', $here),
                is_string($row['unit'] ?? null) ? $row['unit'] : '',
                $this->enum($row, 'format', LessonNumbers::FORMATS, $here),
                $this->enum($row, 'better', self::BETTER, $here),
            );
        }

        if ($kpis === []) {
            throw new InvalidLessonException("{$where}: [kpis] needs at least one entry.");
        }

        return $kpis;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guide(array $data, string $where): ?string
    {
        $guide = $data['guide'] ?? null;

        if ($guide === null) {
            return null;
        }

        if (! is_string($guide) || ! $this->guides->has($guide)) {
            throw new InvalidLessonException("{$where}: guide [".(is_string($guide) ? $guide : 'not a string').'] does not exist.');
        }

        return $guide;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{id: string, text: string}>  $learn
     * @param  list<LessonNode>  $nodes
     * @param  list<LessonEdge>  $edges
     * @param  list<LessonKpi>  $kpis
     * @return list<LessonStepDefinition>
     */
    private function steps(array $data, array $learn, array $nodes, array $edges, array $kpis, string $where): array
    {
        $rows = $this->list($data, 'steps', $where);
        $count = count($rows);

        if ($count < self::STEPS[0] || $count > self::STEPS[1]) {
            throw new InvalidLessonException("{$where}: needs ".self::STEPS[0].' to '.self::STEPS[1]." steps, not {$count}.");
        }

        $nodeIds = array_map(fn (LessonNode $node): string => $node->id, $nodes);
        $edgeIds = array_map(fn (LessonEdge $edge): string => $edge->id, $edges);
        $kpiIds = array_map(fn (LessonKpi $kpi): string => $kpi->id, $kpis);
        $outcomeIds = array_column($learn, 'id');
        $taught = [];
        $steps = [];

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $here = "{$where} step {$number}";
            $role = $this->enum($row, 'role', self::ROLES, $here);

            if ($role === 'summary' && $number !== $count) {
                throw new InvalidLessonException("{$here}: the summary role may only be the last step.");
            }

            $state = $this->statePatch($this->map($row, 'state', $here, false), $nodeIds, $edgeIds, $here);
            $values = $this->kpiSources($row, $kpis, $number, $here);

            $learned = $this->stringList($row, 'learn', $here, false);
            foreach ($learned as $id) {
                if (! in_array($id, $outcomeIds, true)) {
                    throw new InvalidLessonException("{$here}: unknown outcome [{$id}].");
                }
            }
            $taught = [...$taught, ...$learned];

            $body = $this->stringList($row, 'body', $here, true);
            if (count($body) > 3) {
                throw new InvalidLessonException("{$here}: [body] holds at most 3 paragraphs.");
            }

            $callout = $this->callout($row, $kpiIds, $here);

            $steps[] = new LessonStepDefinition(
                number: $number,
                role: $role,
                title: $this->string($row, 'title', $here),
                body: array_map(fn (string $paragraph): string => $this->checkText($paragraph, $kpiIds, $here), $body),
                callout: $callout,
                state: $state,
                kpis: $values,
                changed: $this->changed($row, $kpiIds, $here),
                learn: $learned,
                deeper: $this->deeper($row, $here),
            );
        }

        foreach ($outcomeIds as $id) {
            if (! in_array($id, $taught, true)) {
                throw new InvalidLessonException("{$where}: outcome [{$id}] is taught by no step.");
            }
        }

        return $steps;
    }

    /**
     * Checks a step's patch on the scene and returns it with only the fields it sets.
     *
     * @param  array<array-key, mixed>  $patch
     * @param  list<string>  $nodeIds
     * @param  list<string>  $edgeIds
     * @return array{nodes?: array<string, array{load?: int, queue?: int, status?: string, badge?: ?string}>, edges?: array<string, array{visible?: bool, packets?: array<string, int>}>}
     */
    private function statePatch(array $patch, array $nodeIds, array $edgeIds, string $where): array
    {
        $nodes = [];

        foreach ($this->map($patch, 'nodes', "{$where} state", false) as $id => $change) {
            $id = (string) $id;

            if (! in_array($id, $nodeIds, true) || ! is_array($change)) {
                throw new InvalidLessonException("{$where}: state names unknown node [{$id}].");
            }
            $here = "{$where} node [{$id}]";
            $node = [];

            if (isset($change['load'])) {
                $node['load'] = $this->integer($change, 'load', 0, 100, $here);
            }
            if (isset($change['queue'])) {
                $node['queue'] = $this->integer($change, 'queue', 0, 100000, $here);
            }
            if (isset($change['status'])) {
                $node['status'] = $this->enum($change, 'status', self::NODE_STATUSES, $here);
            }
            if (array_key_exists('badge', $change)) {
                $node['badge'] = $change['badge'] === null ? null : $this->string($change, 'badge', $here);
            }

            $nodes[$id] = $node;
        }

        $edges = [];

        foreach ($this->map($patch, 'edges', "{$where} state", false) as $id => $change) {
            $id = (string) $id;

            if (! in_array($id, $edgeIds, true) || ! is_array($change)) {
                throw new InvalidLessonException("{$where}: state names unknown edge [{$id}].");
            }
            $here = "{$where} edge [{$id}]";
            $edge = [];

            if (isset($change['visible'])) {
                if (! is_bool($change['visible'])) {
                    throw new InvalidLessonException("{$here}: [visible] must be true or false.");
                }
                $edge['visible'] = $change['visible'];
            }

            $packets = [];
            foreach ($this->map($change, 'packets', $here, false) as $kind => $rate) {
                $kind = (string) $kind;

                if (! in_array($kind, self::PACKET_KINDS, true)) {
                    throw new InvalidLessonException("{$here}: unknown packet kind [{$kind}].");
                }
                if (! is_int($rate) || $rate < 0 || $rate > self::MAX_RATE) {
                    throw new InvalidLessonException("{$here}: packet rate for [{$kind}] must be 0 to ".self::MAX_RATE.'.');
                }

                $packets[$kind] = $rate;
            }
            if ($packets !== []) {
                $edge['packets'] = $packets;
            }

            $edges[$id] = $edge;
        }

        return [...($nodes === [] ? [] : ['nodes' => $nodes]), ...($edges === [] ? [] : ['edges' => $edges])];
    }

    /**
     * A step's KPI values: a non-numeric literal, or a pointer into a result file. A KPI a step leaves out keeps the
     * value of the step before, so step 1 must give every one.
     *
     * @param  array<string, mixed>  $row
     * @param  list<LessonKpi>  $kpis
     * @return array<string, array{literal: string}|array{file: string, path: string, scale: int|float, round: ?int, trend: bool}>
     */
    private function kpiSources(array $row, array $kpis, int $number, string $where): array
    {
        $given = $this->map($row, 'kpis', $where, false);
        $ids = array_map(fn (LessonKpi $kpi): string => $kpi->id, $kpis);

        foreach (array_keys($given) as $id) {
            if (! in_array((string) $id, $ids, true)) {
                throw new InvalidLessonException("{$where}: unknown kpi [{$id}].");
            }
        }

        $sources = [];

        foreach ($ids as $id) {
            $here = "{$where} kpi [{$id}]";

            if (array_key_exists($id, $given)) {
                $sources[$id] = $this->kpiSource($given[$id], $here);
            } elseif ($number === 1) {
                throw new InvalidLessonException("{$here}: step 1 must give a value.");
            }
        }

        return $sources;
    }

    /**
     * @return array{literal: string}|array{file: string, path: string, scale: int|float, round: ?int, trend: bool}
     */
    private function kpiSource(mixed $value, string $where): array
    {
        if (is_string($value) && trim($value) !== '' && preg_match('/\d/', $value) !== 1) {
            return ['literal' => $value];
        }

        if (! is_array($value)) {
            throw new InvalidLessonException("{$where}: a literal must be non-numeric text; numbers come from a result file.");
        }

        $scale = $value['scale'] ?? 1;

        if (! is_int($scale) && ! is_float($scale)) {
            throw new InvalidLessonException("{$where}: [scale] must be a number.");
        }

        $trend = $value['trend'] ?? true;

        if (! is_bool($trend)) {
            throw new InvalidLessonException("{$where}: [trend] must be true or false.");
        }

        return [
            'file' => $this->string($value, 'file', $where),
            'path' => $this->string($value, 'path', $where),
            'scale' => $scale,
            'round' => isset($value['round']) ? $this->integer($value, 'round', 0, 6, $where) : null,
            'trend' => $trend,
        ];
    }

    /**
     * The callout is optional; when given it needs a kind and a text.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $kpiIds
     * @return array{kind: string, text: string}|null
     */
    private function callout(array $row, array $kpiIds, string $where): ?array
    {
        if (! isset($row['callout'])) {
            return null;
        }

        $callout = $this->map($row, 'callout', $where);

        return [
            'kind' => $this->enum($callout, 'kind', self::CALLOUT_KINDS, "{$where} callout"),
            'text' => $this->checkText($this->string($callout, 'text', "{$where} callout"), $kpiIds, $where),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $kpiIds
     * @return list<array{sign: string, text: string}>
     */
    private function changed(array $row, array $kpiIds, string $where): array
    {
        $changed = [];

        foreach ($this->list($row, 'changed', $where, false) as $item) {
            $text = $this->string($item, 'text', "{$where} change");

            if (preg_match('/\d/', (string) preg_replace('/\{[^}]*\}/', '', $text)) === 1) {
                throw new InvalidLessonException("{$where}: change [{$text}] holds a number literal; use a {num:...} or {kpi:...} placeholder.");
            }

            $changed[] = [
                'sign' => $this->enum($item, 'sign', self::SIGNS, "{$where} change"),
                'text' => $this->checkText($text, $kpiIds, $where),
            ];
        }

        return $changed;
    }

    /**
     * Checks the placeholders of a text: {kpi:id} names a KPI of the lesson, {num:file#path|format|round} is well formed,
     * and no other brace is left. The text is returned as written; LessonService fills the placeholders in.
     *
     * @param  list<string>  $kpiIds
     */
    private function checkText(string $text, array $kpiIds, string $where): string
    {
        $rest = preg_replace_callback('/\{(kpi|num):([^}]*)\}/', function (array $match) use ($kpiIds, $where): string {
            if ($match[1] === 'kpi') {
                if (! in_array($match[2], $kpiIds, true)) {
                    throw new InvalidLessonException("{$where}: placeholder names unknown kpi [{$match[2]}].");
                }

                return '';
            }

            [$reference, $format, $round] = array_pad(explode('|', $match[2]), 3, null);
            [, $path] = array_pad(explode('#', (string) $reference, 2), 2, '');
            $format ??= 'count';

            if ($path === '' || $format === 'text' || ! in_array($format, LessonNumbers::FORMATS, true) || ($round !== null && ! ctype_digit($round))) {
                throw new InvalidLessonException("{$where}: placeholder [{num:{$match[2]}}] is not file#path|format|round.");
            }

            return '';
        }, $text) ?? $text;

        if (str_contains($rest, '{') || str_contains($rest, '}')) {
            throw new InvalidLessonException("{$where}: text holds an unresolved placeholder.");
        }

        return $text;
    }

    /**
     * A "Go deeper" link is <guide>#<tab>, where the tab is one the guide page shows for that guide. The names the guide
     * page no longer uses (failures, recipes) are mapped to the tab that holds their content.
     *
     * @param  array<string, mixed>  $row
     */
    private function deeper(array $row, string $where): ?string
    {
        $deeper = $row['deeper'] ?? null;

        if ($deeper === null) {
            return null;
        }

        [$key, $tab] = is_string($deeper) ? array_pad(explode('#', $deeper, 2), 2, '') : ['', ''];

        if ($tab === '' || ! $this->guides->has($key)) {
            throw new InvalidLessonException("{$where}: [deeper] must be <guide>#<tab> of an existing guide.");
        }

        $tab = self::RENAMED_TABS[$tab] ?? $tab;
        $known = array_keys([...GuidePageService::TABS, ...GuidePageService::TEACH_TABS]);

        if (! in_array($tab, $known, true)) {
            throw new InvalidLessonException("{$where}: [deeper] names unknown tab [{$tab}]; use one of [".implode(', ', $known).'].');
        }

        try {
            $guide = $this->guides->find($key);
        } catch (InvalidGuideException $exception) {
            throw new InvalidLessonException("{$where}: guide [{$key}] is broken: {$exception->getMessage()}");
        }

        if (! in_array($tab, GuidePageService::tabKeys($guide), true)) {
            throw new InvalidLessonException("{$where}: guide [{$key}] has no [{$tab}] tab.");
        }

        return "{$key}#{$tab}";
    }

    private function path(string $module, string $lesson): string
    {
        return $this->directory.'/'.$module.'/'.$lesson.'.php';
    }

    private function slug(string $value): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function slugField(array $data, string $field, string $where): string
    {
        $slug = $this->string($data, $field, $where);

        if (! $this->slug($slug)) {
            throw new InvalidLessonException("{$where}: slug [{$slug}] may hold only a-z, 0-9 and dashes.");
        }

        return $slug;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $field, string $where): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidLessonException("{$where}: needs a non-empty [{$field}].");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function enum(array $data, string $field, array $allowed, string $where): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidLessonException("{$where}: [{$field}] must be one of [".implode(', ', $allowed).'].');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function integer(array $data, string $field, int $min, int $max, string $where): int
    {
        $value = $data[$field] ?? null;

        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidLessonException("{$where}: [{$field}] must be a whole number from {$min} to {$max}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function list(array $data, string $field, string $where, bool $required = true): array
    {
        $rows = $data[$field] ?? null;

        if ($rows === null && ! $required) {
            return [];
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidLessonException("{$where}: [{$field}] must be a list.");
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidLessonException("{$where}: [{$field}] holds a row that is not an array.");
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function stringList(array $data, string $field, string $where, bool $required): array
    {
        $rows = $data[$field] ?? null;

        if ($rows === null && ! $required) {
            return [];
        }

        if (! is_array($rows) || ! array_is_list($rows) || $rows === [] && $required) {
            throw new InvalidLessonException("{$where}: [{$field}] must be a list of text.");
        }

        foreach ($rows as $row) {
            if (! is_string($row) || trim($row) === '') {
                throw new InvalidLessonException("{$where}: [{$field}] holds an entry that is not text.");
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function map(array $data, string $field, string $where, bool $required = true): array
    {
        $value = $data[$field] ?? null;

        if ($value === null && ! $required) {
            return [];
        }

        if (! is_array($value)) {
            throw new InvalidLessonException("{$where}: [{$field}] must be a map.");
        }

        return $value;
    }
}
