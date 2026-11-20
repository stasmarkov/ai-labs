<?php

declare(strict_types=1);

use App\Enums\GuideFormatType;
use App\Guides\GuideFormatRepository;
use App\Guides\InvalidGuideException;
use App\Guides\SnippetResolver;
use App\Services\GuideFormatPageService;
use App\Services\GuidePageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/guide-format-pages-*') ?: [] as $directory) {
        foreach (glob($directory.'/*/*.php') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($directory.'/*') ?: [] as $folder) {
            @rmdir($folder);
        }
        @rmdir($directory);
    }
});

/**
 * Serves the format pages from a temporary folder holding a copy of the photos set under the given key,
 * without the files named in $without.
 *
 * @param  list<string>  $without
 */
function useFormatFolder(string $key, array $without = []): string
{
    $directory = sys_get_temp_dir().'/guide-format-pages-'.bin2hex(random_bytes(4));
    mkdir($directory.'/'.$key, 0777, true);

    foreach (glob(resource_path('guides/formats/photos/*.php')) ?: [] as $file) {
        if (! in_array(basename($file, '.php'), $without, true)) {
            copy($file, $directory.'/'.$key.'/'.basename($file));
        }
    }

    app()->scoped(GuideFormatRepository::class, fn (): GuideFormatRepository => new GuideFormatRepository($directory, app(SnippetResolver::class)));

    return $directory;
}

/**
 * Drops the parts of a snippet that are read from the live source (its lines and code), so the baseline
 * catches a change to the guide engine or the guide file, not an edit of the code the guide quotes.
 *
 * @param  array<array-key, mixed>  $props
 * @return array<array-key, mixed>
 */
function withoutSnippetSource(array $props): array
{
    if (isset($props['file'], $props['startLine'], $props['endLine'], $props['code'])) {
        unset($props['startLine'], $props['endLine'], $props['code']);
    }

    foreach ($props as $key => $value) {
        if (is_array($value)) {
            $props[$key] = withoutSnippetSource($value);
        }
    }

    return $props;
}

it('AC-1 compare page lists the baseline and five formats with the outcomes', function (): void {
    $this->get('/ai/guides/photos/formats')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/GuideFormats')
            ->has('outcomes', 5)
            ->where('outcomes.0.id', 'LO-1')
            ->where('outcomes.4.id', 'LO-5')
            ->has('rubric', 4)
            ->has('entries', 6)
            ->where('entries.0.key', 'baseline')
            ->where('entries', fn ($entries): bool => collect($entries)->pluck('key')->all() === ['baseline', 'build-along', 'trace', 'quiz', 'debug', 'tour']
                && collect($entries)->every(fn (array $entry): bool => $entry['name'] !== ''
                    && $entry['idea'] !== ''
                    && $entry['time'] !== ''
                    && str_starts_with($entry['href'], '/ai/guides/photos')
                    && array_keys($entry['outcomes']) === ['LO-1', 'LO-2', 'LO-3', 'LO-4', 'LO-5']
                    && ! in_array('', $entry['outcomes'], true)))
            ->where('entries.1.href', '/ai/guides/photos/formats/build-along')
            ->where('entries.0.href', '/ai/guides/photos')
        );
});

it('AC-1 the compare page reads the format items without resolving their code', function (): void {
    useFormatFolder('photos');
    $path = (glob(sys_get_temp_dir().'/guide-format-pages-*/photos/quiz.php') ?: [''])[0];
    file_put_contents($path, str_replace('App\Ai\PhotoVisionAgent::schema', 'App\Ai\Missing::schema', (string) file_get_contents($path)));

    $this->get('/ai/guides/photos/formats')->assertOk();
    expect(fn () => app(GuideFormatRepository::class)->format('photos', GuideFormatType::Quiz))->toThrow(InvalidGuideException::class, 'questions.1.snippet');
});

it('AC-3 unknown key or format is 404', function (): void {
    $this->get('/ai/guides/nope/formats')->assertNotFound();
    $this->get('/ai/guides/describe/formats')->assertNotFound();
    $this->get('/ai/guides/nope/formats/quiz')->assertNotFound();
    $this->get('/ai/guides/photos/formats/nope')->assertNotFound();
    $this->get('/ai/guides/photos/formats/baseline')->assertNotFound();
    $this->get('/ai/guides/..%2Fconfig/formats')->assertNotFound();
});

it('AC-3 an incomplete format folder shows no trial link and answers 404, never 500', function (): void {
    useFormatFolder('photos', ['debug']);

    $this->get('/ai/guides/photos')->assertOk()->assertInertia(fn (Assert $page) => $page->where('formatsHref', null));
    $this->get('/ai/guides/photos/formats')->assertNotFound();
    $this->get('/ai/guides/photos/formats/quiz')->assertNotFound();
    $this->get('/ai/guides/photos/formats/debug')->assertNotFound();
});

it('AC-3 the format service refuses a key without a format set', function (): void {
    $service = app(GuideFormatPageService::class);

    expect($service->format('photos', GuideFormatType::Quiz)['format']['key'])->toBe('quiz')
        ->and(fn () => $service->format('describe', GuideFormatType::Quiz))->toThrow(NotFoundHttpException::class)
        ->and(fn () => $service->compare('nope'))->toThrow(NotFoundHttpException::class);
});

it('AC-8 MNC-3 the photos guide exposes the trial link and the format URLs still answer', function (): void {
    $this->get('/ai/guides/photos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Guide')
            ->where('formatsHref', '/ai/guides/photos/formats')
        );
    $this->get('/ai/guides/describe')->assertOk()->assertInertia(fn (Assert $page) => $page->where('formatsHref', null));
    $this->get('/ai/guides/photos/formats')->assertOk();
    $this->get('/ai/guides/photos/formats/quiz')->assertOk();
});

it('AC-8 the trial link follows the format sets found in the folder', function (): void {
    useFormatFolder('describe');

    $this->get('/ai/guides/describe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('formatsHref', '/ai/guides/describe/formats'));
    $this->get('/ai/guides/describe/formats')->assertOk();
    $this->get('/ai/guides/photos/formats')->assertNotFound();
});

it('F4 F12 a broken compare file fails only the format pages, never the photos guide', function (): void {
    $directory = useFormatFolder('photos');
    file_put_contents($directory.'/photos/_compare.php', "<?php\nreturn ['outcomes' => 'not a list'];");

    $this->get('/ai/guides/photos')->assertOk()->assertInertia(fn (Assert $page) => $page->where('formatsHref', '/ai/guides/photos/formats'));
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/ai/guides/photos/formats'))->toThrow(InvalidGuideException::class);
});

it('MNC-1 the photos guide renders the same props as on master', function (): void {
    $baseline = json_decode((string) file_get_contents(base_path('tests/Fixtures/guides/photos-guide-props.json')), true, flags: JSON_THROW_ON_ERROR);
    $props = $this->get('/ai/guides/photos')->assertOk()->viewData('page')['props']['guide'];

    expect(withoutSnippetSource(json_decode(json_encode($props, JSON_THROW_ON_ERROR), true)))->toBe($baseline)
        ->and($props)->toBe(app(GuidePageService::class)->show('photos'));
});

it('NFR-3 the pages make no application writes and need no outside call', function (): void {
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1 && ! str_contains($query->sql, '"sessions"')) {
            $writes[] = $query->sql;
        }
    });

    $this->get('/ai/guides/photos/formats')->assertOk();
    $this->get('/ai/guides/photos')->assertOk();

    foreach (GuideFormatType::cases() as $format) {
        $this->get('/ai/guides/photos/formats/'.$format->value)->assertOk();
    }

    expect($writes)->toBe([]);
});

it('AC-2 each format page answers 200 with its items and each snippet once', function (string $format, string $component): void {
    $response = $this->get('/ai/guides/photos/formats/'.$format)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('format.key', $format)
            ->missing('outcomes')
            ->has('items')
            ->has('snippets')
            ->where('compareHref', '/ai/guides/photos/formats'));

    $props = $response->viewData('page')['props'];
    $used = collect($props['items'])->pluck('snippet')->reject(fn (mixed $index): bool => $index === null);

    expect($used->every(fn (mixed $index): bool => is_int($index) && isset($props['snippets'][$index])))->toBeTrue()
        ->and($used->unique()->sort()->values()->all())->toBe(array_keys($props['snippets']))
        ->and(collect($props['snippets'])->map(fn (array $snippet): string => $snippet['file'].':'.$snippet['startLine'])->duplicates()->all())->toBe([]);
})->with([
    'build-along' => ['build-along', 'Lab/GuideFormat/BuildAlong'],
    'trace' => ['trace', 'Lab/GuideFormat/Trace'],
    'quiz' => ['quiz', 'Lab/GuideFormat/Quiz'],
    'debug' => ['debug', 'Lab/GuideFormat/Debug'],
    'tour' => ['tour', 'Lab/GuideFormat/Tour'],
]);

it('AC-7 NFR-1 the quiz page shows one question at a time with Next and a final score', function (): void {
    $source = file_get_contents(resource_path('js/ai/Pages/Lab/GuideFormat/Quiz.vue'));

    expect($source)
        ->toContain('currentIndex')
        ->toContain('props.items[currentIndex.value]')
        ->toContain(':disabled="!(current.id in picked)"')
        ->toContain('Score: {{ score }} / {{ items.length }}')
        ->toContain('aria-live="polite"')
        ->toContain('Start over')
        ->not->toContain('v-for="(question, index) in items"');
});

it('AC-7 AC-9 NFR-1 a radio only selects; the answer is committed by the Check button', function (string $page, string $check): void {
    $source = (string) file_get_contents(resource_path("js/ai/Pages/Lab/GuideFormat/{$page}.vue"));

    preg_match_all('/<input\b[^>]*type="radio"[^>]*>/s', $source, $radios);

    expect($radios[0])->not->toBeEmpty();
    foreach ($radios[0] as $radio) {
        expect($radio)->not->toContain('choose(')
            ->not->toContain('@keydown')
            ->not->toContain('@click');
    }

    expect($source)->toMatch('/<button[^>]*@click="choose\([^)]*\)"[^>]*>\s*'.preg_quote($check, '/').'/s');
})->with([
    'quiz' => ['Quiz', 'Check answer'],
    'debug' => ['Debug', 'Check cause'],
]);

it('AC-9 NFR-1 after the answer is shown the debug page marks the right cause and stops offering a retry', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/GuideFormat/Debug.vue'));

    expect($source)->toContain('(the cause)')
        ->toMatch('/v-if="isSolved\(scenario\) && causeIndex === scenario.correct"/')
        ->toMatch('/v-if="!isSolved\(scenario\) && scenario.id in picked[^"]*"[^>]*>[\s\S]*?Try another cause/');
});

it('AC-8 the format pages breadcrumb to the learning area and the photos guide shows a Format trial link in its header', function (): void {
    $pages = ['GuideFormats.vue', 'GuideFormat/BuildAlong.vue', 'GuideFormat/Trace.vue', 'GuideFormat/Quiz.vue', 'GuideFormat/Debug.vue', 'GuideFormat/Tour.vue'];

    foreach ($pages as $page) {
        $source = file_get_contents(base_path('resources/js/ai/Pages/Lab/'.$page));

        expect($source)->not->toContain('href="/ai/guides"')
            ->and($source)->toContain('<Link href="/ai/learn" class="text-ink underline">Learn</Link>');
    }

    $header = file_get_contents(base_path('resources/js/ai/components/lesson/TopicHeader.vue'));
    $guide = file_get_contents(base_path('resources/js/ai/Pages/Lab/Guide.vue'));

    expect($header)->toContain('trialHref')->toContain('Format trial')
        ->and($guide)->toContain(':trial-href="formatsHref"')
        ->and($guide)->not->toContain('Guide format trial')
        ->and($guide)->not->toContain('v-if="trial" class="mt-8');
});
