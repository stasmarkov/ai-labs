<?php

declare(strict_types=1);

use App\Guides\GuideFacts;
use App\Guides\GuideRepository;
use App\Lessons\LessonNumbers;
use App\Lessons\LessonRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);

    $this->lessonsDirectory = sys_get_temp_dir().'/lesson-pages-'.bin2hex(random_bytes(4));
    File::makeDirectory($this->lessonsDirectory.'/mod', 0777, true);

    writeLessonFile($this->lessonsDirectory.'/mod/one.php', lessonFixture());
    writeLessonFile($this->lessonsDirectory.'/course.php', [
        'modules' => [[
            'slug' => 'mod',
            'title' => 'Module',
            'summary' => 'A module.',
            'lessons' => [
                ['slug' => 'one', 'title' => 'One', 'summary' => 'A lesson.', 'level' => 'beginner'],
                ['slug' => 'later', 'title' => 'Later', 'summary' => 'Not yet.', 'level' => 'beginner', 'coming_soon' => true],
            ],
        ]],
    ]);

    $directory = $this->lessonsDirectory;
    $this->app->scoped(LessonRepository::class, fn (): LessonRepository => new LessonRepository($directory, new GuideRepository(resource_path('guides'))));
    $this->app->scoped(LessonNumbers::class, fn (): LessonNumbers => new LessonNumbers(new GuideFacts(base_path('bench/results'))));
});

afterEach(function (): void {
    File::deleteDirectory($this->lessonsDirectory);
});

it('AC-1 the course page lists modules, playable lessons and coming-soon entries', function (): void {
    $this->get('/ai/learn')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Learn')
            ->has('course.modules', 1)
            ->where('course.modules.0.slug', 'mod')
            ->where('course.modules.0.lessons.0.href', '/ai/learn/mod/one')
            ->where('course.modules.0.lessons.0.steps', 8)
            ->where('course.modules.0.lessons.0.comingSoon', false)
            ->where('course.modules.0.lessons.1.comingSoon', true)
            ->where('course.modules.0.lessons.1.href', null)
        );
});

it('AC-1 the navigation has a Learn entry', function (): void {
    $this->get('/ai/learn')
        ->assertInertia(fn (Assert $page) => $page
            ->where('lab.navigation', fn ($navigation): bool => collect($navigation)->contains(
                fn (array $item): bool => $item['label'] === 'Learn' && $item['href'] === '/ai/learn'
            ))
        );
});

it('AC-1 the lesson page props carry the resolved scene and the step', function (): void {
    $this->get('/ai/learn/mod/one')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Lesson')
            ->where('step', 1)
            ->where('lesson.title', 'One')
            ->where('lesson.guide', '/ai/guides/local-models')
            ->has('lesson.nodes', 2)
            ->has('lesson.edges', 2)
            ->has('lesson.kpis', 1)
            ->has('lesson.steps', 8)
            ->has('lesson.steps.0', fn (Assert $step) => $step
                ->hasAll(['number', 'role', 'title', 'body', 'callout', 'nodes', 'edges', 'kpis', 'changed', 'learn', 'deeper'])
                ->etc())
            ->has('course.modules', 1)
        );

    $this->get('/ai/learn/mod/one/3')->assertOk()->assertInertia(fn (Assert $page) => $page->where('step', 3));
});

it('AC-8 unknown modules, lessons, announced lessons and steps are 404', function (string $path): void {
    $this->get($path)->assertNotFound();
})->with([
    'unknown module' => '/ai/learn/nope/one',
    'unknown lesson' => '/ai/learn/mod/nope',
    'coming soon lesson' => '/ai/learn/mod/later',
    'step zero' => '/ai/learn/mod/one/0',
    'step past the end' => '/ai/learn/mod/one/9',
    'step not a number' => '/ai/learn/mod/one/abc',
]);

it('C-10 a broken lesson answers 500 for that lesson only and the course lists it as unavailable', function (): void {
    writeLessonFile($this->lessonsDirectory.'/mod/broken.php', ['title' => 'Broken']);
    writeLessonFile($this->lessonsDirectory.'/course.php', [
        'modules' => [[
            'slug' => 'mod',
            'title' => 'Module',
            'summary' => 'A module.',
            'lessons' => [
                ['slug' => 'one', 'title' => 'One', 'summary' => 'A lesson.', 'level' => 'beginner'],
                ['slug' => 'broken', 'title' => 'Broken', 'summary' => 'A broken lesson.', 'level' => 'beginner'],
            ],
        ]],
    ]);

    $this->get('/ai/learn/mod/broken')->assertStatus(500);
    $this->get('/ai/learn/mod/one')->assertOk();
    $this->get('/ai/learn')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('course.modules.0.lessons.0.available', true)
            ->where('course.modules.0.lessons.1.available', false)
            ->where('course.modules.0.lessons.1.href', null)
        );
});

it('NFR-2 the lesson page props stay under 60 KB', function (): void {
    $props = $this->get('/ai/learn/mod/one')->viewData('page')['props'];

    expect(strlen(json_encode(['lesson' => $props['lesson'], 'step' => $props['step'], 'course' => $props['course']])))->toBeLessThan(60 * 1024);
});

it('MNC-4 the lesson pages write nothing to the database and make no HTTP calls', function (): void {
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|truncate)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);

    $this->get('/ai/learn')->assertOk();
    $this->get('/ai/learn/mod/one/2')->assertOk();

    expect($writes)->toBe([]);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/version'));
    Http::assertNotSent(fn ($request): bool => ! str_ends_with($request->url(), '/api/version'));
});

/**
 * @param  array<string, mixed>  $data
 */
function writeLessonFile(string $path, array $data): void
{
    file_put_contents($path, '<?php return '.var_export($data, true).';');
}

/**
 * @return array<string, mixed>
 */
function lessonFixture(): array
{
    $steps = [];

    foreach (range(1, 8) as $number) {
        $steps[] = [
            'role' => $number === 8 ? 'summary' : 'problem',
            'title' => "Step {$number}",
            'body' => ['Text.'],
            'callout' => ['kind' => 'useful', 'text' => 'Note.'],
            'state' => [],
            'kpis' => $number === 1 ? ['ttft' => 'not measured'] : [],
            'changed' => [],
            'learn' => match ($number) {
                1 => ['lo-1'],
                2 => ['lo-2'],
                3 => ['lo-3'],
                default => [],
            },
        ];
    }

    $steps[0]['state'] = ['edges' => ['e1' => ['packets' => ['request' => 2]]]];
    $steps[1]['state'] = ['edges' => ['e2' => ['packets' => ['token' => 1]]]];

    return [
        'title' => 'One',
        'summary' => 'A lesson.',
        'minutes' => 5,
        'level' => 'beginner',
        'learn' => [
            ['id' => 'lo-1', 'text' => 'First.'],
            ['id' => 'lo-2', 'text' => 'Second.'],
            ['id' => 'lo-3', 'text' => 'Third.'],
        ],
        'guide' => 'local-models',
        'scene' => [
            'nodes' => [
                ['id' => 'a', 'kind' => 'browser', 'label' => 'A', 'x' => 100, 'y' => 100],
                ['id' => 'b', 'kind' => 'model', 'label' => 'B', 'x' => 600, 'y' => 300],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'a', 'to' => 'b'],
                ['id' => 'e2', 'from' => 'b', 'to' => 'a'],
            ],
        ],
        'kpis' => [['id' => 'ttft', 'label' => 'First token', 'unit' => '', 'format' => 's', 'better' => 'lower']],
        'steps' => $steps,
    ];
}

/**
 * @return array<string, string>
 */
function lessonPlayerSources(): array
{
    $sources = [];

    foreach ([
        'page' => 'Pages/Lab/Lesson.vue',
        'course' => 'Pages/Lab/Learn.vue',
        'scene' => 'components/lesson/SceneCanvas.vue',
        'kpis' => 'components/lesson/KpiStrip.vue',
        'panel' => 'components/lesson/StepPanel.vue',
        'bar' => 'components/lesson/StepBar.vue',
        'learn' => 'components/lesson/LearnList.vue',
        'sidebar' => 'components/lesson/CourseSidebar.vue',
        'text' => 'components/lesson/LessonText.vue',
        'keys' => 'composables/lessonKeys.ts',
        'progress' => 'composables/useLessonProgress.ts',
        'motion' => 'composables/useReducedMotion.ts',
    ] as $name => $path) {
        $sources[$name] = (string) file_get_contents(resource_path('js/ai/'.$path));
    }

    return $sources;
}

it('AC-2 the lesson page is built from the scene, KPI strip, panel, step bar, learn list and sidebar', function (): void {
    $page = lessonPlayerSources()['page'];

    foreach (['SceneCanvas', 'KpiStrip', 'StepPanel', 'StepBar', 'LearnList', 'CourseSidebar'] as $component) {
        expect($page)->toContain("import {$component} from");
        expect($page)->toContain("<{$component}");
    }
});

it('AC-7 step changes are announced through an aria-live region', function (): void {
    $sources = lessonPlayerSources();

    expect($sources['page'])->toContain('aria-live="polite"')
        ->and($sources['page'])->toContain('aria-atomic="true"');
});

it('AC-3 pause freezes the scene transitions as well as the packets', function (): void {
    $scene = lessonPlayerSources()['scene'];

    expect($scene)->toContain("paused ? 'scene-paused'")
        ->and($scene)->toMatch('/\.scene-paused \.scene-fill,\s*\.scene-paused \.scene-bar\s*\{\s*transition: none;/');
});

it('AC-7 the scene has a reduced-motion branch with static packet markers and a text equivalent', function (): void {
    $sources = lessonPlayerSources();

    expect($sources['motion'])->toContain('(prefers-reduced-motion: reduce)')
        ->and($sources['scene'])->toContain('useReducedMotion')
        ->and($sources['scene'])->toContain('v-if="reducedMotion"')
        ->and($sources['scene'])->toContain('stroke-dasharray')
        ->and($sources['scene'])->toContain('class="sr-only"')
        ->and($sources['scene'])->toContain('prefers-reduced-motion');
});

it('AC-3 the keyboard handler ignores modifiers, inputs and keeps Space native on buttons and links', function (): void {
    $keys = lessonPlayerSources()['keys'];

    foreach (['ctrlKey', 'metaKey', 'altKey', 'INPUT', 'TEXTAREA', 'SELECT', 'isContentEditable', "'ArrowRight'", "'ArrowLeft'", "'r'", "' '", "'BUTTON'", "'A'"] as $needle) {
        expect($keys)->toContain($needle);
    }

    expect(lessonPlayerSources()['page'])->toContain('lessonActionFor');
});

it('F15 moving between steps is a client-side Inertia visit: a history entry, no request and no resent lesson', function (): void {
    $page = lessonPlayerSources()['page'];

    expect($page)->toContain('router.push(')
        ->and($page)->toContain('preserveState: true')
        ->and($page)->not->toContain('router.get')
        ->and($page)->not->toContain('router.visit')
        ->and($page)->not->toContain('router.reload')
        ->and($page)->not->toContain('replace: true');
});

it('F9 a lesson file that the course does not list is not playable', function (): void {
    writeLessonFile($this->lessonsDirectory.'/mod/draft.php', lessonFixture());

    $this->get('/ai/learn/mod/one')->assertOk();
    $this->get('/ai/learn/mod/draft')->assertNotFound();
    $this->get('/ai/learn/mod/draft/2')->assertNotFound();
});

it('F14 F17 the scene draws its nodes once and moves packets outside Vue, only while it can be seen', function (): void {
    $sources = lessonPlayerSources();
    $packets = (string) file_get_contents(resource_path('js/ai/components/lesson/PacketLayer.vue'));

    expect($packets)->toContain('requestAnimationFrame')
        ->and($packets)->toContain('cancelAnimationFrame')
        ->and($packets)->toContain('setAttribute(')
        ->and($packets)->toContain('IntersectionObserver')
        ->and($packets)->toContain('visibilitychange')
        ->and($packets)->toContain('document.hidden')
        ->and($packets)->not->toContain('v-for')
        ->and($sources['scene'])->not->toContain('requestAnimationFrame(tick)')
        ->and($sources['scene'])->not->toContain('clock');
});

it('F10 F13 progress read from localStorage is checked field by field and bad data falls back to empty', function (): void {
    $progress = lessonPlayerSources()['progress'];

    expect($progress)->toContain('function sanitize(')
        ->and($progress)->toContain('Number.isInteger')
        ->and($progress)->toContain("typeof record.done !== 'boolean'")
        ->and($progress)->toContain('Array.isArray');
});

it('F6 the course sidebar follows the 1024 px breakpoint when the window is resized', function (): void {
    $sidebar = lessonPlayerSources()['sidebar'];

    expect($sidebar)->toContain("'(min-width: 1024px)'")
        ->and($sidebar)->toContain("addEventListener('change'")
        ->and($sidebar)->toContain("removeEventListener('change'");
});

it('AC-6 progress lives in localStorage under one versioned key and can be reset', function (): void {
    $sources = lessonPlayerSources();

    expect($sources['progress'])->toContain("'ai-lab:learn:v1'")
        ->and($sources['progress'])->toContain('resetLesson')
        ->and($sources['progress'])->toContain('resetAll')
        ->and($sources['course'])->toContain('reset progress')
        ->and($sources['sidebar'])->toContain('reset this lesson');
});

it('NFR-3 lesson text is rendered without raw HTML and every control shows keyboard focus', function (): void {
    $sources = lessonPlayerSources();

    foreach ($sources as $source) {
        expect($source)->not->toContain('v-html');
    }

    foreach (['bar', 'sidebar', 'scene'] as $name) {
        expect($sources[$name])->toContain('focus-visible:');
    }
});

it('NFR-3 every scene SVG text colour has a contrast of at least 4.5:1 against its fill', function (): void {
    $source = file_get_contents(resource_path('js/ai/components/lesson/SceneCanvas.vue'));

    preg_match("/statusFills[^=]*= \{([^}]*)\}/", $source, $fillBlock);
    preg_match_all('/#[0-9a-fA-F]{6}/', $fillBlock[1], $fills);

    $luminance = function (string $hex): float {
        $channels = array_map(function (int $value): float {
            $c = $value / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, sscanf($hex, '#%02x%02x%02x'));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    };
    $ratio = function (string $a, string $b) use ($luminance): float {
        [$hi, $lo] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    };

    expect($fills[0])->toHaveCount(3);

    $pairs = [];
    foreach ($fills[0] as $fill) {
        $pairs[] = ['#1a1916', $fill];
    }
    $pairs[] = ['#ffffff', '#c2410c'];
    $pairs[] = ['#1a1916', '#ffffff'];

    foreach ($pairs as [$text, $background]) {
        expect($ratio($text, $background))->toBeGreaterThanOrEqual(4.5);
    }
});
