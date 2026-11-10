<?php

declare(strict_types=1);

use App\Services\LabOverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

it('AC-3 the home page renders the landing page through the lab entry', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertViewIs('ai.app')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('title', 'ai-lab')
            ->has('about')
            ->has('runSteps', 4)
            ->where('runSteps.0.command', 'ddev setup')
            ->where('runSteps.1.command', 'ollama pull '.config('ai.providers.ollama.models.text.default'))
            ->where('runSteps.2.command', 'ollama pull '.config('ai.providers.ollama.models.embeddings.default'))
            ->where('runSteps.3.label', 'Use the local models')
            ->where('runSteps.3.note', fn (string $note): bool => str_contains($note, 'fake-stream'))
            ->where('labHref', '/ai')
            ->has('lab.navigation')
            ->has('lab.textModel')
            ->has('capabilities', 6)
            ->has('capabilities.0', fn (Assert $card) => $card
                ->where('key', 'describe')
                ->where('route', '/ai/describe')
                ->where('guideKey', 'describe')
                ->has('summary')
                ->etc())
            ->has('coming', 0)
            ->has('serverModes', 3)
            ->where('serverModes.0.key', 'frankenphp')
            ->where('serverModes.1.key', 'frankenphp-worker')
            ->where('serverModes.2.key', 'frankenphp-octane')
            ->where('guidesHref', '/ai/guides'));
});

it('AC-3 the capability map comes from the lab capabilities', function (): void {
    $ready = array_values(array_filter(
        app(LabOverviewService::class)->capabilities(),
        fn ($capability): bool => $capability->status === 'ready',
    ));

    $keys = array_column($this->get('/')->viewData('page')['props']['capabilities'], 'key');

    expect($keys)->toBe(array_map(fn ($capability): string => $capability->key, $ready));
});

it('AC-3 every internal route link resolves', function (): void {
    $props = $this->get('/')->viewData('page')['props'];

    $routes = [$props['labHref'], ...array_column($props['capabilities'], 'route')];

    foreach ($routes as $href) {
        $matched = Route::getRoutes()->match(Request::create($href, 'GET'));
        expect($matched->getName())->not->toBeNull();
        $this->get($href)->assertOk();
    }
});

it('AC-3 every capability card points at an existing guide', function (): void {
    $props = $this->get('/')->viewData('page')['props'];

    foreach ([...$props['capabilities'], ...$props['coming']] as $card) {
        $this->get('/ai/guides/'.$card['guideKey'])->assertOk();
    }
});
