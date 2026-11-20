<?php

declare(strict_types=1);

use App\DTOs\Lesson;
use App\Lessons\LessonNumbers;
use App\Lessons\LessonRepository;
use App\Lessons\LessonService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

const PILOT_MODULE = 'running-models';
const PILOT_LESSON = 'one-model-many-users';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);
});

function pilot(): Lesson
{
    $lesson = app(LessonService::class)->find(PILOT_MODULE, PILOT_LESSON);

    expect($lesson)->not->toBeNull();

    return $lesson;
}

/**
 * Every result file a lesson file points at, from its kpi pointers and its {num:file#path} placeholders.
 *
 * @return list<string>
 */
function lessonResultFiles(string $path): array
{
    $files = [];
    $walk = function (mixed $value) use (&$walk, &$files): void {
        if (is_array($value)) {
            if (is_string($value['file'] ?? null)) {
                $files[] = $value['file'];
            }
            array_map($walk, $value);
        } elseif (is_string($value) && preg_match_all('/\{num:([^#}]+)#/', $value, $matches) > 0) {
            $files = [...$files, ...$matches[1]];
        }
    };
    $walk(require $path);

    return array_values(array_unique($files));
}

it('AC-1 the course lists three modules and eight lessons, and every one of them is playable', function (): void {
    $modules = app(LessonRepository::class)->course()->modules;

    expect(array_map(fn ($module): string => $module->slug, $modules))->toBe(['running-models', 'answers-you-can-trust', 'agents'])
        ->and(array_map(fn ($module): int => count($module->lessons), $modules))->toBe([3, 3, 2]);

    $playable = [];
    foreach ($modules as $module) {
        foreach ($module->lessons as $entry) {
            if (! $entry->comingSoon) {
                $playable[] = "{$module->slug}/{$entry->slug}";
            }
        }
    }

    expect($playable)->toBe([
        PILOT_MODULE.'/'.PILOT_LESSON,
        'running-models/streaming-an-answer',
        'running-models/choosing-a-model-and-provider',
        'answers-you-can-trust/structured-output-and-validation',
        'answers-you-can-trust/retrieval-with-citations',
        'answers-you-can-trust/caching-answers',
        'agents/a-tool-loop-with-caps-and-confirmation',
        'agents/mcp-and-a-research-agent',
    ]);

    $this->get('/ai/learn')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Learn')
            ->has('course.modules', 3)
            ->where('course.modules.0.lessons.0.href', '/ai/learn/running-models/one-model-many-users')
            ->where('course.modules.0.lessons.0.steps', 10)
            ->where('course.modules.1.lessons.0.comingSoon', false)
            ->where('course.modules.2.lessons.0.comingSoon', false)
        );
});

it('AC-2 the pilot opens on step 1 with the scene, KPIs, panel, learn list and course', function (): void {
    $this->get('/ai/learn/running-models/one-model-many-users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Lesson')
            ->where('step', 1)
            ->has('lesson.nodes')
            ->has('lesson.edges')
            ->has('lesson.kpis')
            ->has('lesson.learn')
            ->has('lesson.steps', 10)
            ->where('lesson.steps.0.role', 'problem')
            ->has('lesson.steps.0.body')
            ->has('lesson.steps.0.callout')
            ->has('lesson.steps.0.changed')
            ->has('course.modules', 3)
        );
});

it('AC-2 the pilot page props stay well under the payload budget', function (): void {
    $response = $this->get('/ai/learn/running-models/one-model-many-users')->assertOk();

    expect(strlen(json_encode($response->viewData('page')['props'])))->toBeLessThan(60_000);
});

it('AC-4 the pilot loads: every KPI and number placeholder resolves from a committed file', function (): void {
    $lesson = pilot();

    expect($lesson->steps)->toHaveCount(10);

    foreach ($lesson->steps as $step) {
        expect($step->kpis)->toHaveCount(count($lesson->kpis));

        foreach ($step->kpis as $kpi) {
            expect($kpi['value'])->not->toBe('')->not->toContain('{');
        }

        $text = [...$step->body, $step->callout['text'], ...array_column($step->changed, 'text')];
        foreach ($text as $paragraph) {
            expect($paragraph)->not->toContain('{')->not->toContain('}');
        }
    }
});

it('AC-4 every result file the pilot reads is tracked by git and none is under raw', function (): void {
    $version = Process::path(base_path())->run(['git', 'rev-parse', '--is-inside-work-tree']);

    if (! $version->successful()) {
        $this->markTestSkipped('git is not available here.');
    }

    $files = lessonResultFiles(resource_path('lessons/'.PILOT_MODULE.'/'.PILOT_LESSON.'.php'));

    expect($files)->toContain('llm/2026-11-13/run.json')
        ->and($files)->toContain('metrics/2026-11-16/sse-frankenphp-auto.json');

    foreach ($files as $file) {
        expect($file)->not->toStartWith('raw/');

        $result = Process::path(base_path())->run(['git', 'ls-files', '--error-unmatch', '--', 'bench/results/'.$file]);

        expect($result->successful())->toBeTrue("bench/results/{$file} is not tracked by git");
    }
});

it('F1 every Go deeper link of the pilot opens a tab the guide page shows', function (): void {
    $this->withoutVite();
    $deeper = array_values(array_filter(array_map(fn ($step): ?string => $step->deeper, pilot()->steps)));

    expect($deeper)->not->toBeEmpty();

    foreach ($deeper as $link) {
        [$key, $tab] = explode('#', $link, 2);
        $props = $this->get('/ai/guides/'.$key)->assertOk()->viewData('page')['props']['guide'];
        $keys = array_column([...$props['tabs'], ...$props['teachTabs']], 'key');

        expect($keys)->toContain($tab);
    }
});

it('F5 the pilot colours a number only when it compares like with like', function (): void {
    $steps = pilot()->steps;
    $trend = fn (int $number, string $id): string => collect($steps[$number - 1]->kpis)->firstWhere('id', $id)['trend'];
    $value = fn (int $number, string $id): string => collect($steps[$number - 1]->kpis)->firstWhere('id', $id)['value'];

    expect($trend(3, 'ttft'))->toBe('worse')
        ->and($trend(5, 'search'))->toBe('worse')
        ->and($trend(5, 'ttft'))->toBe('neutral')
        ->and($trend(6, 'search'))->toBe('better');

    foreach (['ttft', 'speed', 'search'] as $id) {
        expect($trend(9, $id))->toBe('neutral', "step 9 {$id} switches model")
            ->and($trend(10, $id))->toBe('neutral', "step 10 {$id} sums up");
    }

    expect($value(10, 'speed'))->toBe($value(1, 'speed'), 'the summary shows the first model only')
        ->and(implode(' ', $steps[9]->body))->toContain('first model');
});

it('AC-4 every outcome of the pilot is taught by a step and every step outcome exists', function (): void {
    $lesson = pilot();
    $outcomes = array_column($lesson->learn, 'id');
    $taught = [];

    foreach ($lesson->steps as $step) {
        foreach ($step->learn as $id) {
            expect($outcomes)->toContain($id);
            $taught[$id] = true;
        }
    }

    expect(array_keys($taught))->toEqualCanonicalizing($outcomes)
        ->and(count($outcomes))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6);
});

it('AC-4 the pilot shows the measured values the spec names, and BENCHMARKS.md prints them rounded', function (): void {
    $numbers = app(LessonNumbers::class);
    $benchmarks = (string) file_get_contents(base_path('BENCHMARKS.md'));

    $checks = [
        ['llm/2026-11-13/run.json', 'models."qwen3.5:9b".speed.ttft_ms.median', 286.1, '286'],
        ['llm-load/2026-11-13/search-under-load.json', 'servers.1.with_load.p95_ms', 2723.57, '2724'],
        ['llm-load/2026-11-13/search-under-load.json', 'servers.0.with_load.p95_ms', 237.37, '237'],
    ];

    foreach ($checks as [$file, $path, $exact, $rounded]) {
        expect($numbers->read($file, $path, 'spot-check'))->toBe($exact)
            ->and($benchmarks)->toContain($rounded);
    }

    $lesson = pilot();
    $values = fn (string $id): array => array_map(
        fn ($step): string => collect($step->kpis)->firstWhere('id', $id)['value'],
        $lesson->steps,
    );

    expect($values('ttft')[0])->toBe('0.3 s')
        ->and($values('speed')[0])->toBe('51.6 tok/s')
        ->and($values('search')[0])->toBe('64 ms')
        ->and($values('search')[4])->toBe('2,724 ms')
        ->and($values('search')[5])->toBe('237 ms');
});

it('AC-4 the pilot teaches in the right order and ends on a summary', function (): void {
    $steps = pilot()->steps;

    expect(array_map(fn ($step): string => $step->role, $steps))->toBe([
        'problem', 'useful', 'problem', 'problem', 'careful', 'solution', 'solution', 'careful', 'useful', 'summary',
    ]);
});

it('AC-4 the pilot points at guides and tabs that exist', function (): void {
    $lesson = pilot();

    expect($lesson->guide)->not->toBeNull();

    foreach ($lesson->steps as $step) {
        if ($step->deeper !== null) {
            expect($step->deeper)->toContain('#');
        }
    }
});

it('AC-4 no lesson text carries a slice id, a document name or a repository path', function (): void {
    $lesson = pilot();
    $text = [$lesson->title, $lesson->summary, ...array_column($lesson->learn, 'text')];

    foreach ($lesson->steps as $step) {
        $text = [...$text, $step->title, ...$step->body, $step->callout['text'], ...array_column($step->changed, 'text')];
    }

    foreach ($text as $line) {
        expect($line)->not->toMatch('/\bSL-\d|\.md\b|bench\/|resources\/|app\//');
    }
});

it('Scope-5 Scope-7 the spec records both pilot deviations and the backlog names the SL-54 pick', function (): void {
    $spec = file_get_contents(base_path('.ai/specs/spec-20261119-2146-scene-lessons/spec-lite.md'));
    $decisions = substr($spec, strpos($spec, '## Decisions'), strpos($spec, '## Open questions') - strpos($spec, '## Decisions'));
    $backlog = file_get_contents(base_path('backlog.md'));
    preg_match('/^### SL-54 .*?^- \*\*Status:\*\* (.+)$/ms', $backlog, $status);

    expect($decisions)->toContain('Another model, same queue')
        ->and($decisions)->toContain('slowest searches')
        ->and($decisions)->toContain('**D-7:**')
        ->and($decisions)->toContain('max_total_threads')
        ->and($decisions)->toContain('max_busy_workers')
        ->and($status[1] ?? '')->toContain('scene lessons')
        ->and($status[1])->toContain('SL-55');

    $pick = file_get_contents(base_path('.ai/specs/spec-20261117-2243-guide-format-variants/decisions.md'));
    $pick = substr($pick, strpos($pick, '## Owner pick'));

    expect($pick)->toContain('Follow one photo')
        ->and($pick)->toContain('reused as a later lesson');
});

const COURSE_LESSONS = [
    'streaming-an-answer' => ['running-models', 'streaming-an-answer'],
    'choosing-a-model-and-provider' => ['running-models', 'choosing-a-model-and-provider'],
    'structured-output-and-validation' => ['answers-you-can-trust', 'structured-output-and-validation'],
    'retrieval-with-citations' => ['answers-you-can-trust', 'retrieval-with-citations'],
    'caching-answers' => ['answers-you-can-trust', 'caching-answers'],
    'a-tool-loop-with-caps-and-confirmation' => ['agents', 'a-tool-loop-with-caps-and-confirmation'],
    'mcp-and-a-research-agent' => ['agents', 'mcp-and-a-research-agent'],
];

/**
 * Two measured figures per lesson: the result file and path, the scale, the round the lesson shows, and the figure as
 * BENCHMARKS.md prints it. A null print means BENCHMARKS.md has no such figure (the research stats are not in it); the
 * lesson display is still checked against the result file.
 */
const COURSE_SPOT_CHECKS = [
    'streaming-an-answer' => [
        ['chat-stop/2026-11-03/frankenphp.json', 'median_probe_ms_after_stop', 1, 0, '75 ms'],
        ['llm/2026-11-13/run.json', 'models."qwen3.5:9b".speed.ttft_ms.median', 0.001, 1, '286 ms'],
    ],
    'choosing-a-model-and-provider' => [
        ['llm/2026-11-13/run.json', 'models."gemma4:12b".speed.ttft_ms.median', 0.001, 1, '429 ms'],
        ['photo-vision/2026-11-11/run.json', 'models.1.valid_first_attempt.rate', 100, 0, '0.860 (43/50)'],
    ],
    'structured-output-and-validation' => [
        ['photo-vision/2026-11-11/run.json', 'models.1.valid_first_attempt.rate', 100, 0, '0.860 (43/50)'],
        ['nl-search/2026-11-09/run.json', 'models.0.exact_match.rate', 100, 0, '0.950 (38/40)'],
    ],
    'retrieval-with-citations' => [
        ['rag-eval/2026-11-03/recall-500-300/recall.json', 'cells.1.recall', 100, 0, '0.875'],
        ['rag-eval/2026-11-03/answers-qwen-500-300/answers.json', 'models.0.across_runs.citation_rate.median', 100, 0, '0.96'],
    ],
    'caching-answers' => [
        ['docs-cache/2026-11-03/latency/hit-miss.json', 'paths.exact.median_ms_by_path.exact', 1, 0, '51 ms'],
        ['docs-cache/2026-11-03/latency/hit-miss.json', 'paths.fresh.median_ms_all', 0.001, 1, '5687 ms'],
    ],
    'a-tool-loop-with-caps-and-confirmation' => [
        ['assistant/2026-11-12/run.json', 'models.0.latency_ms.p50', 0.001, 1, '3782'],
        ['assistant/2026-11-12/run.json', 'models.0.argument_validity_first_try.rate', 100, 0, '1.000'],
    ],
    'mcp-and-a-research-agent' => [
        ['research/2026-11-14/stats.json', 'summary.average_model_calls', 1, 1, null],
        ['research/2026-11-14/stats.json', 'summary.average_duration_s', 1, 0, null],
    ],
];

function courseLesson(string $slug): Lesson
{
    [$module, $lesson] = COURSE_LESSONS[$slug];
    $found = app(LessonService::class)->find($module, $lesson);

    expect($found)->not->toBeNull();

    return $found;
}

/**
 * Every line of text a lesson shows, with the placeholders still in it.
 *
 * @return list<string>
 */
function courseLessonRawText(string $slug): array
{
    [$module, $lesson] = COURSE_LESSONS[$slug];
    $data = require resource_path("lessons/{$module}/{$lesson}.php");
    $text = [$data['title'], $data['summary'], ...array_column($data['learn'], 'text'), ...array_column($data['kpis'], 'label')];

    foreach ($data['steps'] as $step) {
        $text = [...$text, $step['title'], ...$step['body'], $step['callout']['text'], ...array_column($step['changed'], 'text')];

        foreach ($step['state']['nodes'] ?? [] as $node) {
            if (is_string($node['badge'] ?? null)) {
                $text[] = $node['badge'];
            }
        }
    }

    return $text;
}

it('AC-2 MNC-2 every number of a course lesson comes from a tracked result file and no lesson text has a digit', function (string $slug): void {
    $files = lessonResultFiles(resource_path('lessons/'.COURSE_LESSONS[$slug][0].'/'.$slug.'.php'));

    expect($files)->not->toBeEmpty();

    if (Process::path(base_path())->run(['git', 'rev-parse', '--is-inside-work-tree'])->successful()) {
        foreach ($files as $file) {
            expect($file)->not->toStartWith('raw/');

            $result = Process::path(base_path())->run(['git', 'ls-files', '--error-unmatch', '--', 'bench/results/'.$file]);

            expect($result->successful())->toBeTrue("bench/results/{$file} is not tracked by git");
        }
    }

    foreach (courseLessonRawText($slug) as $line) {
        expect(preg_replace('/\{num:[^}]*\}/', '', $line))->not->toMatch('/\d/', "a digit in: {$line}");
    }

    foreach (courseLesson($slug)->steps as $step) {
        expect($step->kpis)->toHaveCount(count(courseLesson($slug)->kpis));

        foreach ($step->kpis as $kpi) {
            expect($kpi['value'])->not->toBe('')->not->toContain('{');
        }

        foreach ([...$step->body, $step->callout['text'], ...array_column($step->changed, 'text')] as $paragraph) {
            expect($paragraph)->not->toContain('{')->not->toContain('}');
        }
    }
})->with(array_keys(COURSE_LESSONS));

it('AC-2 two figures of each course lesson match the result file and BENCHMARKS.md', function (string $slug): void {
    $numbers = app(LessonNumbers::class);
    $benchmarks = (string) file_get_contents(base_path('BENCHMARKS.md'));
    $shown = collect(courseLesson($slug)->steps)->flatMap(fn ($step): array => [...$step->body, ...array_column($step->changed, 'text'), ...array_column($step->kpis, 'value')])->implode("\n");

    foreach (COURSE_SPOT_CHECKS[$slug] as [$file, $path, $scale, $round, $printed]) {
        $value = round($numbers->read($file, $path, 'spot-check') * $scale, $round);

        if ($printed !== null) {
            expect($benchmarks)->toContain($printed);
        }

        expect($shown)->toContain(number_format($value, $round));
    }
})->with(array_keys(COURSE_LESSONS));

it('AC-3 a course lesson has 8 to 13 steps, ends on a summary and teaches every outcome', function (string $slug): void {
    $lesson = courseLesson($slug);
    $roles = array_map(fn ($step): string => $step->role, $lesson->steps);
    $outcomes = array_column($lesson->learn, 'id');
    $taught = [];

    expect(count($lesson->steps))->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(13)
        ->and(array_count_values($roles)['summary'] ?? 0)->toBe(1)
        ->and(end($roles))->toBe('summary')
        ->and(count($outcomes))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(6)
        ->and($lesson->guide)->not->toBeNull();

    foreach ($lesson->steps as $step) {
        expect(count($step->body))->toBeLessThanOrEqual(3)
            ->and(mb_strlen(implode('', $step->body)))->toBeLessThanOrEqual(600);

        foreach ($step->learn as $id) {
            expect($outcomes)->toContain($id);
            $taught[$id] = true;
        }
    }

    expect(array_keys($taught))->toEqualCanonicalizing($outcomes);

    foreach (courseLessonRawText($slug) as $line) {
        expect($line)->not->toMatch('/\bSL-\d|\.md\b|bench\/|resources\/|app\//');
    }
})->with(array_keys(COURSE_LESSONS));

it('AC-3 every Go deeper link of a course lesson opens a tab its guide page shows, and the lesson page renders', function (string $slug): void {
    $this->withoutVite();
    [$module, $lesson] = COURSE_LESSONS[$slug];
    $deeper = array_values(array_unique(array_filter(array_map(fn ($step): ?string => $step->deeper, courseLesson($slug)->steps))));

    expect($deeper)->not->toBeEmpty();

    foreach ($deeper as $link) {
        [$key, $tab] = explode('#', $link, 2);
        $props = $this->get('/ai/guides/'.$key)->assertOk()->viewData('page')['props']['guide'];

        expect(array_column([...$props['tabs'], ...$props['teachTabs']], 'key'))->toContain($tab);
    }

    $this->get("/ai/learn/{$module}/{$lesson}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Lab/Lesson')->where('step', 1));
})->with(array_keys(COURSE_LESSONS));
