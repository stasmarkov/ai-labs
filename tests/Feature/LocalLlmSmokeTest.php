<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\Listing;
use App\Rules\ListingFilterRules;
use App\Rules\ModerationRules;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

const SMOKE_DEFAULT_URL = 'http://host.docker.internal:11434';

const SMOKE_DEFAULT_MODEL = 'qwen3:0.6b';

function smokeModel(): string
{
    return (string) (env('OLLAMA_SMOKE_MODEL') ?: SMOKE_DEFAULT_MODEL);
}

function smokeUrl(): string
{
    return rtrim((string) (env('OLLAMA_URL') ?: SMOKE_DEFAULT_URL), '/');
}

/**
 * Skips unless the opt-in switch is set, Ollama answers and the smoke model is pulled. The switch is checked
 * first, so the default run never opens a connection.
 */
function prepareSmoke(mixed $test): void
{
    if (env('LOCAL_LLM_SMOKE') !== true && env('LOCAL_LLM_SMOKE') !== '1') {
        $test->markTestSkipped('Local model smoke tests are opt-in: run `ddev exec env LOCAL_LLM_SMOKE=1 php artisan test --group=local-llm`.');
    }

    Http::allowStrayRequests();

    $model = smokeModel();

    try {
        $tags = Http::timeout(5)->get(smokeUrl().'/api/tags');
    } catch (Throwable) {
        $test->markTestSkipped("Ollama is not reachable. Start it on the host, then run on the host: ollama pull {$model}");
    }

    $names = collect($tags->json('models', []))->pluck('name')->all();

    if (! $tags->successful() || (! in_array($model, $names, true) && ! in_array($model.':latest', $names, true))) {
        $test->markTestSkipped("The smoke model is not available. Run on the host: ollama pull {$model}");
    }

    $overrides = ['ai.settings_ui' => false, 'ai.providers.ollama.url' => smokeUrl()];

    foreach (['describe', 'nl-search', 'moderation'] as $feature) {
        $overrides["ai.features.{$feature}.provider"] = 'ollama';
        $overrides["ai.features.{$feature}.model"] = $model;
    }

    config($overrides);
}

it('MNC-2 keeps the default run offline: the config does not enable the group and a stray request is refused', function (): void {
    $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

    expect($phpunit)->not->toContain('LOCAL_LLM_SMOKE')->not->toContain('local-llm');

    Http::fake(['known.test/*' => Http::response([])]);
    Http::preventStrayRequests();

    expect(fn () => Http::get('http://unfaked.invalid/api/tags'))->toThrow(RuntimeException::class);
});

it('AC-7 describe streams at least one non-empty text event and ends with [DONE]', function (): void {
    prepareSmoke($this);

    $listing = Listing::factory()->for(Dealer::factory())->create(['make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018]);

    $response = $this->get('/listings/'.$listing->id.'/describe')->assertOk();

    $lines = array_values(array_filter(explode("\n", $response->streamedContent()), fn (string $line): bool => $line !== ''));
    $deltas = [];

    foreach (array_slice($lines, 0, -1) as $line) {
        $event = json_decode(substr($line, 6), true, flags: JSON_THROW_ON_ERROR);

        if (($event['type'] ?? null) === 'text_delta' && ($event['delta'] ?? '') !== '') {
            $deltas[] = $event['delta'];
        }
    }

    expect($lines)->not->toBeEmpty()
        ->and(end($lines))->toBe('data: [DONE]')
        ->and($deltas)->not->toBeEmpty();
})->group('local-llm');

it('AC-7 natural-language search returns filters that pass the filter rules, or 422, never a server error', function (): void {
    prepareSmoke($this);

    Listing::factory()->for(Dealer::factory())->create(['make' => 'BMW', 'year' => 2019]);

    $response = $this->getJson('/api/listings/nl?q='.urlencode('diesel BMW after 2018 under 20000'));

    expect($response->status())->toBeIn([200, 422]);

    if ($response->status() === 200) {
        $filters = (array) $response->json('data.filters');

        expect(Validator::make($filters, ListingFilterRules::rules())->passes())->toBeTrue();
    }
})->group('local-llm');

it('AC-7 moderation is classified or invalid, and a classified result keeps its risk and reasons in bounds', function (): void {
    prepareSmoke($this);

    $response = $this->postJson('/api/moderation', [
        'title' => 'Golf 7 1.6 TDI',
        'price_eur' => 5000,
        'make' => 'Volkswagen',
        'model' => 'Golf',
        'year' => 2018,
        'mileage_km' => 90000,
        'city' => 'Berlin',
        'description' => 'Well kept car, service book, two keys.',
    ]);

    expect($response->status())->toBe(200)
        ->and($response->json('data.status'))->toBeIn(['classified', 'invalid']);

    if ($response->json('data.status') === 'classified') {
        expect($response->json('data.risk'))->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1)
            ->and(array_diff($response->json('data.reasons'), ModerationRules::REASONS))->toBe([]);
    }
})->group('local-llm');
