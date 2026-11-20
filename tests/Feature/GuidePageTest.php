<?php

declare(strict_types=1);

use App\DTOs\LabCapability;
use App\Guides\GuideFacts;
use App\Guides\GuideRepository;
use App\Guides\InvalidGuideException;
use App\Lessons\LessonNumbers;
use App\Lessons\LessonRepository;
use App\Lessons\LessonService;
use App\Lessons\LessonTopics;
use App\Services\GuidePageService;
use App\Services\LabOverviewService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);
});

it('AC-1 guides index redirects to learn', function (): void {
    $this->get('/ai/guides')->assertStatus(301)->assertRedirect('/ai/learn');
    $this->get('/ai/guides/')->assertStatus(301)->assertRedirect('/ai/learn');
});

it('AC-7 MNC-4 navigation is grouped in order and conditional items follow today\'s availability rules', function (): void {
    $groups = fn (): array => $this->get('/ai')->viewData('page')['props']['lab']['navigation'];
    $labels = fn (array $navigation): array => array_map(
        fn (array $group): array => [$group['label'], array_column($group['items'], 'label')],
        $navigation,
    );

    app()->detectEnvironment(fn (): string => 'production');
    config(['ai.settings_ui' => false, 'ai.mcp.enabled' => false, 'ai.admin_assistant.enabled' => false]);

    expect($labels($groups()))->toBe([
        ['Learn', ['Overview', 'Course']],
        ['Text', ['Describe', 'Chat', 'Moderation']],
        ['Search', ['Semantic search', 'NL search', 'Docs search']],
        ['Photos', ['Photos', 'Photo search', 'Duplicate photos']],
        ['Agents', ['Assistant', 'Research']],
    ])
        ->and($groups()[0]['items'])->toBe([
            ['href' => '/ai', 'label' => 'Overview'],
            ['href' => '/ai/learn', 'label' => 'Course'],
        ]);

    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.settings_ui' => true, 'ai.mcp.enabled' => true, 'ai.admin_assistant.enabled' => true]);

    $navigation = $labels($groups());
    $flat = array_merge(...array_map(fn (array $group): array => $group[1], $navigation));

    expect(array_column($navigation, 0))->toBe(['Learn', 'Text', 'Search', 'Photos', 'Agents', 'Setup'])
        ->and($flat)->toContain('Playground', 'Admin assistant', 'Changes', 'Settings')
        ->and($navigation[1][1])->toBe(['Describe', 'Chat', 'Moderation', 'Playground'])
        ->and($navigation[4][1])->toBe(['Assistant', 'Research', 'Admin assistant', 'Changes'])
        ->and($navigation[5][1])->toBe(['Settings']);
});

it('F20 C-12 every lab page sits in a sidebar group, so a page missing from the groups cannot vanish silently', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.settings_ui' => true, 'ai.mcp.enabled' => true, 'ai.admin_assistant.enabled' => true]);

    $hrefs = collect($this->get('/ai')->viewData('page')['props']['lab']['navigation'])->flatMap(fn (array $group): array => array_column($group['items'], 'href'));
    $pages = collect(app(LabOverviewService::class)->capabilities())->map(fn (LabCapability $page): string => $page->route);

    expect($hrefs->duplicates()->all())->toBe([])
        ->and($hrefs->sort()->values()->all())->toBe($pages->merge(['/ai', '/ai/learn', '/ai/changes', '/ai/settings'])->sort()->values()->all());
});

it('AC-4 an unknown guide key returns 404', function (): void {
    $this->get('/ai/guides/nope')->assertNotFound();
    $this->get('/ai/guides/..%2Fconfig')->assertNotFound();
});

it('AC-2 a capability guide has the six tabs, each with content', function (string $key): void {
    $this->get('/ai/guides/'.$key)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Guide')
            ->where('guide.key', $key)
            ->where('guide.tabs', fn ($tabs): bool => collect($tabs)->pluck('key')->all() === ['works', 'implementation', 'modify', 'support', 'numbers', 'experiments'])
            ->where('guide.works', fn ($works): bool => count($works) >= 3)
            ->where('guide.implementation', fn ($items): bool => count($items) >= 2
                && collect($items)->every(fn (array $item): bool => $item['snippet'] !== null))
            ->where('guide.recipes', fn ($recipes): bool => count($recipes) >= 2)
            ->where('guide.support', fn ($rows): bool => count($rows) >= 1)
            ->where('guide.failures', fn ($failures): bool => count($failures) >= 2)
            ->where('guide.numbers', fn ($numbers): bool => count($numbers) >= 1
                && collect($numbers)->every(fn (array $number): bool => $number['value'] !== ''))
            ->where('guide.experiments', fn ($experiments): bool => count($experiments) >= 1
                && collect($experiments)->every(fn (array $experiment): bool => str_starts_with($experiment['href'], '/ai/')))
        );
})->with(['describe', 'semantic', 'nl-search', 'docs', 'chat', 'photos', 'local-models', 'metrics', 'logs-restart', 'worker-dx', 'lessons']);

it('AC-2 the guide page resolves related keys to titles', function (): void {
    $this->get('/ai/guides/describe')
        ->assertInertia(fn (Assert $page) => $page
            ->where('guide.related', fn ($related): bool => collect($related)->every(
                fn (array $item): bool => $item['key'] !== '' && $item['title'] !== ''
            ))
        );
});

it('NFR-2 the guide page follows the WAI-ARIA tabs pattern in its source', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/Guide.vue'));

    expect($source)
        ->toContain('role="tablist"')
        ->toContain('role="tab"')
        ->toContain('role="tabpanel"')
        ->toContain(':aria-selected=')
        ->toContain(':aria-controls=')
        ->toContain(':aria-labelledby=')
        ->toContain(':tabindex="active === tab.key ? 0 : -1"')
        ->toContain('ArrowRight')
        ->toContain('ArrowLeft')
        ->toContain('Home')
        ->toContain('End');
});

it('NFR-2 code blocks scroll inside the block and carry a caption', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/components/LabCode.vue'));

    expect($source)->toContain('overflow-x-auto')->toContain('<figcaption');
});

it('AC-6 every experiment preset URL uses only parameters its target page reads', function (): void {
    $known = [
        'ai.describe' => [],
        'ai.semantic' => ['q'],
        'ai.docs' => ['q'],
        'ai.chat' => ['q'],
        'ai.nl-search' => ['q'],
        'ai.photos' => [],
        'ai.photo-search' => ['q'],
        'ai.photos.duplicates' => [],
        'ai.assistant' => ['q'],
        'ai.moderation' => [],
        'ai.admin-assistant' => ['q'],
        'ai.research' => [],
        'ai.playground' => [],
        'ai.changes' => [],
        'ai.learn.index' => [],
    ];
    app()->detectEnvironment(fn (): string => 'local');
    $repository = app(GuideRepository::class);

    foreach ($repository->keys() as $key) {
        foreach ($repository->find($key)->experiments as $experiment) {
            expect($known)->toHaveKey($experiment->page);
            expect(array_diff(array_keys($experiment->params), $known[$experiment->page]))->toBe([]);

            $path = parse_url(route($experiment->page, $experiment->params, false), PHP_URL_PATH);
            $this->get($path)->assertOk();
        }
    }
});

it('AC-6 the live pages that accept q prefill their field without auto-running', function (): void {
    foreach (['Semantic', 'NlSearch', 'Docs', 'Chat', 'PhotoSearch'] as $page) {
        $source = (string) file_get_contents(resource_path("js/ai/Pages/Lab/{$page}.vue"));

        expect($source)->toContain('ref(presetQuery())');
    }

    $helper = (string) file_get_contents(resource_path('js/ai/composables/usePresetQuery.ts'));
    expect($helper)->toContain("get('q')");
});

it('AC-6 the experiment card stores the prediction locally and links to the live page', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/components/LabExperiment.vue'));

    expect($source)->toContain('localStorage')->toContain('Run it')->toContain('What you measured');
});

it('AC-6 the experiment props carry predictions and the answer HTML, and the card is a radio group with a match result', function (): void {
    $this->get('/ai/guides/describe')->assertInertia(fn (Assert $page) => $page
        ->where('guide.experiments', fn ($experiments): bool => count($experiments) >= 1
            && collect($experiments)->every(fn (array $experiment): bool => count($experiment['predictions']) >= 2
                && count($experiment['predictions']) <= 4
                && in_array($experiment['answer']['id'], array_column($experiment['predictions'], 'id'), true)
                && is_string($experiment['answer']['html']) && $experiment['answer']['html'] !== '')));

    $source = (string) file_get_contents(resource_path('js/ai/components/LabExperiment.vue'));

    expect($source)->toContain('type="radio"')->toContain('localStorage')->toContain('did not match')->toContain('matched');
});

it('AC-5 a guide page shows the build, watch and elsewhere sections with the same text as the page data', function (string $key): void {
    $teach = app(GuidePageService::class)->teach($key)->toArray();

    $this->get('/ai/guides/'.$key)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Guide')
            ->where('guide.tabs', fn ($tabs): bool => collect($tabs)->pluck('key')->all() === ['works', 'implementation', 'modify', 'support', 'numbers', 'experiments'])
            ->where('guide.teachTabs', fn ($tabs): bool => collect($tabs)->pluck('key')->all() === ['build', 'watch', 'elsewhere'])
            ->where('guide.build', fn ($rows): bool => collect($rows)->map(fn (array $row): array => [$row['step'], $row['detail'], $row['tab'], $row['doc']])->all()
                === collect($teach['build'])->map(fn (array $row): array => [$row['step'], $row['detail'], $row['tab'], $row['doc']])->all())
            ->where('guide.watch', fn ($rows): bool => collect($rows)->pluck('point')->all() === collect($teach['watch'])->pluck('point')->all())
            ->where('guide.elsewhere', fn ($rows): bool => collect($rows)->pluck('domain')->all() === collect($teach['elsewhere'])->pluck('domain')->all()
                && collect($rows)->pluck('how')->all() === collect($teach['elsewhere'])->pluck('how')->all())
        );
})->with(['describe', 'semantic', 'nl-search', 'docs', 'chat', 'photos', 'photo-search', 'photo-duplicates', 'assistant', 'moderation', 'research', 'playground', 'admin-assistant', 'providers', 'mcp']);

it('AC-5 a guide with no teach data has no teach tabs', function (): void {
    $directory = sys_get_temp_dir().'/guides-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory.'/plain.php', "<?php\nreturn ['kind' => 'foundation', 'title' => 'Plain', 'summary' => 'No teach.', 'works' => [['title' => 'One', 'body' => 'Body.']]];");
    app()->bind(GuideRepository::class, fn (): GuideRepository => new GuideRepository($directory));

    $this->get('/ai/guides/plain')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('guide.teachTabs', [])->where('guide.build', []));

    unlink($directory.'/plain.php');
    rmdir($directory);
});

it('AC-5 the guide page renders the teach tabs and opens the one named in the URL hash', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/Guide.vue'));

    expect($source)
        ->toContain('guide.teachTabs')
        ->toContain("tab.key === 'build'")
        ->toContain("tab.key === 'watch'")
        ->toContain("tab.key === 'elsewhere'")
        ->toContain('window.location.hash');
});

it('C-13 both guides carry the pilot lesson link, a malformed key fails validation, and an unlisted or coming-soon one is not linked', function (): void {
    foreach (['local-models', 'metrics'] as $key) {
        $this->get('/ai/guides/'.$key)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('topic.lessons', fn ($lessons): bool => collect($lessons)->contains(
                    fn (array $lesson): bool => $lesson['href'] === '/ai/learn/running-models/one-model-many-users' && $lesson['title'] === 'One model, many users'
                ))
            );
    }

    $directory = sys_get_temp_dir().'/guides-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $guide = fn (string $lessons): string => "<?php\nreturn ['kind' => 'foundation', 'title' => 'Plain', 'summary' => 'S.', 'works' => [['title' => 'One', 'body' => 'Body.']], 'lessons' => [{$lessons}]];";
    file_put_contents($directory.'/known.php', $guide("'running-models/one-model-many-users', 'running-models/no-such-lesson', 'running-models/streaming-an-answer'"));
    file_put_contents($directory.'/malformed.php', $guide("'one-model-many-users'"));
    $repository = new GuideRepository($directory);

    expect($repository->find('known')->lessons)->toBe(['running-models/one-model-many-users', 'running-models/no-such-lesson', 'running-models/streaming-an-answer']);
    expect(fn () => $repository->find('malformed'))->toThrow(InvalidGuideException::class);
    unlink($directory.'/malformed.php');

    $lessons = $directory.'/lessons';
    mkdir($lessons.'/running-models', 0777, true);
    touch($lessons.'/running-models/one-model-many-users.php');
    file_put_contents($lessons.'/course.php', "<?php\nreturn ['modules' => [['slug' => 'running-models', 'title' => 'Running models', 'summary' => 'S.', 'lessons' => [\n"
        ."['slug' => 'one-model-many-users', 'title' => 'One model, many users', 'summary' => 'S.', 'level' => 'beginner'],\n"
        ."['slug' => 'streaming-an-answer', 'title' => 'Streaming an answer', 'summary' => 'S.', 'level' => 'beginner', 'coming_soon' => true],\n"
        .']]]];');

    $this->app->instance(GuideRepository::class, $repository);
    $this->app->instance(LessonRepository::class, new LessonRepository($lessons, $repository));
    $this->app->forgetInstance(LessonService::class);
    $this->app->forgetInstance(LessonTopics::class);
    $this->get('/ai/guides/known')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('topic.lessons', [
            ['key' => 'running-models/one-model-many-users', 'title' => 'One model, many users', 'href' => '/ai/learn/running-models/one-model-many-users'],
        ]));

    array_map('unlink', [...glob($directory.'/*.php'), ...glob($lessons.'/*.php'), ...glob($lessons.'/running-models/*.php')]);
    rmdir($lessons.'/running-models');
    rmdir($lessons);
    rmdir($directory);
});

it('F16 the guide page takes lesson titles from the course file and never builds a lesson', function (): void {
    $this->app->scoped(LessonNumbers::class, fn (): LessonNumbers => new LessonNumbers(new GuideFacts(sys_get_temp_dir().'/no-results-here')));

    $this->get('/ai/guides/local-models')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('topic.lessons.0.title', 'One model, many users'));
});

it('AC-6 every course lesson is linked from the guide it teaches, and the lessons guide, learning guide and README describe the course', function (): void {
    $links = [
        'local-models' => ['running-models/one-model-many-users', 'running-models/choosing-a-model-and-provider'],
        'streaming' => ['running-models/streaming-an-answer'],
        'providers' => ['running-models/choosing-a-model-and-provider'],
        'nl-search' => ['answers-you-can-trust/structured-output-and-validation'],
        'chat' => ['running-models/streaming-an-answer'],
        'moderation' => ['answers-you-can-trust/structured-output-and-validation'],
        'photos' => ['answers-you-can-trust/structured-output-and-validation'],
        'docs' => ['answers-you-can-trust/retrieval-with-citations', 'answers-you-can-trust/caching-answers'],
        'assistant' => ['agents/a-tool-loop-with-caps-and-confirmation'],
        'admin-assistant' => ['agents/a-tool-loop-with-caps-and-confirmation'],
        'mcp' => ['agents/mcp-and-a-research-agent'],
        'research' => ['agents/mcp-and-a-research-agent'],
    ];

    foreach ($links as $key => $lessons) {
        $this->get('/ai/guides/'.$key)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('topic.lessons', fn ($listed): bool => collect($lessons)->every(
                    fn (string $lesson): bool => collect($listed)->contains(fn (array $item): bool => $item['href'] === '/ai/learn/'.$lesson)
                ))
            );
    }

    expect(file_get_contents(base_path('README.md')))->not->toContain('listed as coming soon')->not->toContain('the other lessons are listed');
    expect(file_get_contents(base_path('docs/learning-guide.md')))->not->toContain('the pilot, "One model, many users"');
    expect(file_get_contents(resource_path('guides/lessons.php')))->toContain('eight lessons');
});

it('AC-6 the lessons guide page and the README name every course lesson', function (): void {
    $titles = collect((require resource_path('lessons/course.php'))['modules'])
        ->flatMap(fn (array $module): array => $module['lessons'])
        ->pluck('title')
        ->all();

    expect($titles)->toHaveCount(8);

    $response = $this->get('/ai/guides/lessons')->assertOk();
    $props = json_encode($response->viewData('page')['props']['guide'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $readme = file_get_contents(base_path('README.md'));

    foreach ($titles as $title) {
        expect($props)->toContain($title);
        expect($readme)->toContain($title);
    }
});

it('F3 AC-6 the guide a course lesson names lists that lesson, so the lesson and its guide link each other', function (): void {
    $guides = app(GuideRepository::class);

    foreach ((require resource_path('lessons/course.php'))['modules'] as $module) {
        foreach ($module['lessons'] as $entry) {
            $key = $module['slug'].'/'.$entry['slug'];
            $guide = (require resource_path("lessons/{$key}.php"))['guide'];

            expect($guides->find($guide)->lessons)->toContain($key);
        }
    }
});

it('F19 the lessons guide builds its list from the course file, and the README lists the same lessons in the same order', function (): void {
    $modules = array_values(array_filter((require resource_path('lessons/course.php'))['modules'], fn (array $module): bool => $module['lessons'] !== []));
    $works = collect((require resource_path('guides/lessons.php'))['works'])->firstWhere('title', 'The eight lessons');

    foreach ($modules as $module) {
        expect($works['body'])->toContain('**'.$module['title'].':** '.implode('; ', array_column($module['lessons'], 'title')).'.');
    }

    preg_match_all('/^- (.+?): (.+)\.$/m', (string) file_get_contents(base_path('README.md')), $lines, PREG_SET_ORDER);
    $readme = collect($lines)->mapWithKeys(fn (array $line): array => [$line[1] => explode('; ', $line[2])])->only(array_column($modules, 'title'))->all();

    expect($readme)->toBe(collect($modules)->mapWithKeys(fn (array $module): array => [$module['title'] => array_column($module['lessons'], 'title')])->all());

    expect((string) file_get_contents(resource_path('guides/lessons.php')))->toContain("require __DIR__.'/../lessons/course.php'");
});

it('AC-5 AC-6 guide page carries topic and course', function (): void {
    $this->get('/ai/guides/local-models')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('topic.module.slug', 'running-models')
            ->where('topic.module.title', 'Running models')
            ->where('topic.lessons.0.href', '/ai/learn/running-models/one-model-many-users')
            ->has('course.modules', 5)
            ->where('course.modules.0.references.0', ['key' => 'describe', 'title' => 'Describe a car', 'href' => '/ai/guides/describe'])
            ->where('course.modules.0.lessons.0', ['slug' => 'one-model-many-users', 'title' => 'One model, many users', 'href' => '/ai/learn/running-models/one-model-many-users'])
            ->where('course.modules.0', fn ($module): bool => array_keys(collect($module)->all()) === ['slug', 'title', 'lessons', 'references'])
            ->missing('guide.lessons')
        );

    $this->get('/ai/guides/describe')
        ->assertInertia(fn (Assert $page) => $page
            ->where('topic.module.slug', 'running-models')
            ->where('topic.lessons', [])
        );

    $this->get('/ai/guides/lessons')
        ->assertInertia(fn (Assert $page) => $page->where('topic', null));
});

it('NFR-1 C-17 every guide page payload stays under 60 000 characters', function (): void {
    $sizes = [];

    foreach (app(GuideRepository::class)->keys() as $key) {
        preg_match('#<script data-page="app" type="application/json">(.*?)</script>#s', (string) $this->get('/ai/guides/'.$key)->assertOk()->getContent(), $match);
        $sizes[$key] = mb_strlen($match[1] ?? str_repeat('x', 60000));
    }

    arsort($sizes);

    expect($sizes)->toHaveCount(25)
        ->and(min($sizes))->toBeGreaterThan(5000)
        ->and(reset($sizes))->toBeLessThan(60000, 'largest guide page: '.array_key_first($sizes));
});

it('AC-6 a lesson-linked guide names its first lesson by key for the course sidebar', function (): void {
    $this->get('/ai/guides/streaming')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('topic.lessons.0.key', fn (string $key): bool => '/ai/learn/'.$key === $page->toArray()['props']['topic']['lessons'][0]['href'])
        );
});

it('AC-10 AC-2 the lessons guide says a topic is placed by the references list or a guide lessons link, and the course stops loading otherwise', function (): void {
    $response = $this->get('/ai/guides/lessons')->assertOk();
    $guide = $response->viewData('page')['props']['guide'];
    $text = html_entity_decode(strip_tags(json_encode($guide, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

    expect($text)
        ->toContain('one module\'s references list')
        ->toContain('guide\'s lessons link')
        ->toContain('named by at least one guide')
        ->toContain('stop loading')
        ->toContain('names the key');
});

it('F1 a broken guide file fails only its own page: the course, a lesson and an unrelated guide still answer 200', function (): void {
    $root = sys_get_temp_dir().'/guides-'.bin2hex(random_bytes(4));
    File::copyDirectory(resource_path('guides'), $root.'/guides');
    File::copyDirectory(resource_path('lessons'), $root.'/lessons');
    file_put_contents($root.'/guides/worker-dx.php', "<?php\nreturn ['kind' => 'no-such-kind', 'title' => 'T', 'summary' => 'S.'];");
    file_put_contents($root.'/guides/run-log.php', "<?php\nreturn ['kind' => ");
    $this->app->scoped(GuideRepository::class, fn (): GuideRepository => new GuideRepository($root.'/guides'));
    $this->app->scoped(LessonRepository::class, fn (): LessonRepository => new LessonRepository($root.'/lessons', $this->app->make(GuideRepository::class)));
    $this->app->forgetScopedInstances();

    $this->get('/ai/learn')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('course.modules', 5)
            ->where('course.modules.4.slug', 'running-the-server')
            ->where('course.modules.4.references', fn ($references): bool => collect($references)->pluck('key')->all() === ['logs-restart'])
        );
    $this->get('/ai/learn/running-models/streaming-an-answer')->assertOk();
    $this->get('/ai/guides/chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('topic.module.slug', 'running-models')
            ->where('course.modules.4.references', fn ($references): bool => ! collect($references)->pluck('key')->contains('worker-dx'))
        );
    $this->get('/ai/guides/worker-dx')->assertStatus(500);

    File::deleteDirectory($root);
});

it('F2 a guide page with lesson links still renders when the course file is broken', function (): void {
    $this->app->instance(LessonRepository::class, new LessonRepository(sys_get_temp_dir().'/no-such-lessons', app(GuideRepository::class)));
    $this->app->forgetInstance(LessonService::class);
    $this->app->forgetInstance(LessonTopics::class);

    $this->get('/ai/guides/chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->missing('guide.lessons')->where('topic', null));
});
