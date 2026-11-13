<?php

declare(strict_types=1);

use App\Guides\GuideRepository;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);
});

it('AC-4 the index lists every guide with title and summary and labels the planned one', function (): void {
    $keys = app(GuideRepository::class)->keys();

    $this->get('/ai/guides')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Guides')
            ->has('guides', count($keys))
            ->where('guides', fn ($guides): bool => collect($guides)->every(
                fn (array $guide): bool => $guide['title'] !== '' && $guide['summary'] !== ''
            ))
            ->where('guides', fn ($guides): bool => collect($guides)->firstWhere('key', 'photos')['planned'] === false
                && collect($guides)->firstWhere('key', 'photos')['group'] === 'capability'
                && collect($guides)->firstWhere('key', 'describe')['planned'] === false
                && collect($guides)->firstWhere('key', 'describe')['group'] === 'capability'
                && collect($guides)->firstWhere('key', 'providers')['group'] === 'foundation')
        );
});

it('AC-4 the navigation has a Guides entry', function (): void {
    $this->get('/ai/guides')
        ->assertInertia(fn (Assert $page) => $page
            ->where('lab.navigation', fn ($navigation): bool => collect($navigation)->contains(
                fn (array $item): bool => $item['label'] === 'Guides' && $item['href'] === '/ai/guides'
            ))
        );
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
})->with(['describe', 'semantic', 'nl-search', 'docs', 'chat', 'photos', 'local-models']);

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
        'ai.changes' => [],
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
