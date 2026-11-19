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

it('AC-1 the course lists three modules and eight lessons, and only the pilot is playable', function (): void {
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

    expect($playable)->toBe([PILOT_MODULE.'/'.PILOT_LESSON]);

    $this->get('/ai/learn')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Learn')
            ->has('course.modules', 3)
            ->where('course.modules.0.lessons.0.href', '/ai/learn/running-models/one-model-many-users')
            ->where('course.modules.0.lessons.0.steps', 10)
            ->where('course.modules.0.lessons.1.comingSoon', true)
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
