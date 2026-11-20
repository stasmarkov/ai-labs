<?php

declare(strict_types=1);

use App\Services\LabOverviewService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

const LOCAL_ONLY_UUID = '01a1dbd1-9958-71bf-8fe3-6a9f07da4c99';

/**
 * Every playground route as [method, uri]; the guard test below fails when a route is added without being listed.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function playgroundRoutes(): array
{
    return [
        'page' => ['GET', '/ai/playground'],
        'page of a run' => ['GET', '/ai/playground/'.LOCAL_ONLY_UUID],
        'prompt list' => ['GET', '/api/playground/prompts'],
        'prompt' => ['GET', '/api/playground/prompts/1'],
        'create prompt' => ['POST', '/api/playground/prompts'],
        'add version' => ['POST', '/api/playground/prompts/1/versions'],
        'delete prompt' => ['DELETE', '/api/playground/prompts/1'],
        'start run' => ['POST', '/api/playground/runs'],
        'run list' => ['GET', '/api/playground/runs'],
        'run' => ['GET', '/api/playground/runs/'.LOCAL_ONLY_UUID],
    ];
}

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
});

it('AC-8 lists every playground route in this test', function (): void {
    $actual = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => Str::contains($route->uri(), 'playground'))
        ->map(fn ($route): string => $route->methods()[0].' /'.preg_replace('/\{[^}]+\}/', '#', $route->uri()))
        ->sort()->values()->all();

    $listed = collect(playgroundRoutes())
        ->map(fn (array $route): string => $route[0].' '.preg_replace(['/'.LOCAL_ONLY_UUID.'/', '#/prompts/1#'], ['#', '/prompts/#'], $route[1]))
        ->sort()->values()->all();

    expect($listed)->toBe($actual);
});

it('AC-8 answers 404 on every playground route outside the local environment', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);

    foreach (playgroundRoutes() as $label => [$method, $uri]) {
        $response = str_starts_with($uri, '/api/') ? $this->json($method, $uri, $method === 'POST' ? ['input' => 'x'] : []) : $this->get($uri);

        expect($response->status())->toBe(404, "{$label} ({$method} {$uri}) answered {$response->status()}");

        if (str_starts_with($uri, '/api/')) {
            expect($response->json('code'))->toBe('local_only', $label);
        }
    }
})->with(['production', 'testing', 'staging']);

it('AC-8 does not reach a model or the database for a request outside local', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->postJson('/api/playground/runs', ['input' => 'x', 'targets' => [['provider' => 'ollama', 'model' => 'm'], ['provider' => 'ollama', 'model' => 'n']]])->assertNotFound();

    Http::assertNothingSent();
});

it('AC-8 hides the navigation entry and the capability card outside local and shows them locally', function (): void {
    $service = app(LabOverviewService::class);

    app()->detectEnvironment(fn (): string => 'production');

    $links = array_filter(collect($service->topBar()['navigation'])->pluck('items')->flatten(1)->all(), fn (array $link): bool => $link['href'] === '/ai/playground');
    $keys = array_map(fn ($capability): string => $capability->key, $service->capabilities());

    expect($links)->toBe([])
        ->and($keys)->not->toContain('playground');
    expect(fn () => $service->capability('playground'))->toThrow(InvalidArgumentException::class);

    app()->detectEnvironment(fn (): string => 'local');

    $links = array_filter(collect($service->topBar()['navigation'])->pluck('items')->flatten(1)->all(), fn (array $link): bool => $link['href'] === '/ai/playground');

    expect($links)->toHaveCount(1)
        ->and($service->capability('playground')->route)->toBe('/ai/playground');
});
