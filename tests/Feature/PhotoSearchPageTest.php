<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

it('AC-6 the lab page offers the query field, the search endpoint, example chips and the prompt facts', function (): void {
    $this->get('/ai/photo-search')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/PhotoSearch')
            ->where('searchUrl', '/api/photos/search')
            ->where('minQueryLength', 2)
            ->where('maxQueryLength', 200)
            ->where('promptTemplate', fn (string $template) => str_contains($template, '{query}'))
            ->where('promptVersion', 'photo-search@v1')
            ->where('minScore', fn ($score) => (float) $score === (float) config('ai.photo_search.min_score'))
            ->where('guideHref', '/ai/guides/photo-search')
            ->where('examples', fn ($examples) => count($examples) >= 3)
            ->has('howItWorks')
            ->has('embeddingsProvider.provider')
            ->has('embeddingsProvider.model')
            ->where('photoUrlPrefix', fn (string $prefix) => str_starts_with($prefix, '/'))
            ->where('listingUrlTemplate', fn (string $url) => str_contains($url, '/listings/') && str_contains($url, 'LISTING_ID')));
});

it('AC-6 the page sources show the field, chips, grid, tags, how it works panel and empty states', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/PhotoSearch.vue'));

    expect($source)->toContain('ref(presetQuery())')
        ->toContain('LabHowItWorks')
        ->toContain('LabTag')
        ->toContain('searchJson')
        ->toContain('examples')
        ->toContain('No photos matched')
        ->not->toContain('onMounted(search');
});

it('AC-6 the lab lists the page in the navigation and as an overview capability with a guide', function (): void {
    $overview = $this->get('/ai')->viewData('page')['props'];
    $keys = array_column($overview['capabilities'], 'key');

    expect($keys)->toContain('photo-search');

    $capability = $overview['capabilities'][array_search('photo-search', $keys, true)];

    expect($capability['route'])->toBe('/ai/photo-search')
        ->and($capability['status'])->toBe('ready')
        ->and($capability['guideHref'])->toBe('/ai/guides/photo-search');

    $this->get($capability['guideHref'])->assertOk();

    $navigation = $this->get('/ai/photo-search')->viewData('page')['props']['lab']['navigation'] ?? [];

    expect(array_column($navigation, 'href'))->toContain('/ai/photo-search');
});

it('AC-6 the page links each result to the photo detail through a photoUrlTemplate', function (): void {
    $this->get('/ai/photo-search')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('photoUrlTemplate', fn (string $url) => str_starts_with($url, '/ai/photos?photo=') && str_contains($url, 'PHOTO_ID')));

    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/PhotoSearch.vue'));

    expect($source)->toContain('photoUrlTemplate')
        ->toContain('photoHref(match)');
});

it('AC-4 the page shows the label and the source (database or cache) from the response meta', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/PhotoSearch.vue'));
    $composable = (string) file_get_contents(resource_path('js/ai/composables/useSearchRequest.ts'));

    expect($source)->toContain('meta?.label')
        ->toContain('meta?.cached')
        ->toContain('from cache')
        ->toContain('caption="source"')
        ->and($composable)->toContain('meta');
});

it('AC-6 the page offers the four spec example chips', function (): void {
    $this->get('/ai/photo-search')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('examples', ['red convertible', 'white SUV in the snow', 'BMW 3 Series', 'a car on a race track']));
});

it('AC-2 the page pushes the refused event when the 422 body is a refusal', function (): void {
    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/PhotoSearch.vue'));
    $composable = (string) file_get_contents(resource_path('js/ai/composables/useSearchRequest.ts'));

    expect($source)->toContain("'refused'")
        ->toContain('outcome.refused')
        ->and($composable)->toContain('refused');
});
