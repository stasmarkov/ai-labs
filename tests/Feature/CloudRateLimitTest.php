<?php

declare(strict_types=1);

use App\Ai\ListingFilterAgent;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    config([
        'ai.default' => 'fake-instant',
        'ai.providers.anthropic.key' => 'test-key',
        'ai.cloud_per_minute' => 2,
        'ai.nl_search.per_minute' => 100,
        'ai.describe_per_minute' => 100,
    ]);
});

function nlEmptyFilter(): array
{
    return ['make' => null, 'model' => null, 'city' => null, 'fuel' => null, 'min_year' => null, 'max_price_eur' => null, 'unsupported' => []];
}

it('AC-7 a cloud provider request over the cloud-ai limit answers 429 with no model call', function (): void {
    ListingFilterAgent::fake([nlEmptyFilter(), nlEmptyFilter()]);

    $this->getJson('/api/listings/nl?q=a&provider=anthropic')->assertOk();
    $this->getJson('/api/listings/nl?q=b&provider=anthropic')->assertOk();
    $limited = $this->getJson('/api/listings/nl?q=c&provider=anthropic');

    $limited->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->toBeNumeric()
        ->and($limited->json('message'))->toBe('Too Many Attempts.')
        ->and(AiRun::count())->toBe(2);
});

it('AC-7 a feature configured with a cloud provider is limited without a provider parameter', function (): void {
    config(['ai.features.nl-search.provider' => 'anthropic']);
    ListingFilterAgent::fake([nlEmptyFilter(), nlEmptyFilter()]);

    $this->getJson('/api/listings/nl?q=a')->assertOk();
    $this->getJson('/api/listings/nl?q=b')->assertOk();
    $this->getJson('/api/listings/nl?q=c')->assertStatus(429);
});

it('AC-7 describe on a cloud provider is limited too', function (): void {
    $listing = Listing::factory()->for(Dealer::factory())->create();
    config(['ai.cloud_per_minute' => 1, 'ai.features.describe.provider' => 'anthropic']);

    Http::fake(['*' => Http::response('', 500)]);
    $this->get('/listings/'.$listing->id.'/describe');
    $this->get('/listings/'.$listing->id.'/describe')->assertStatus(429);
});

it('AC-7 local providers never touch the cloud limiter', function (): void {
    config(['ai.cloud_per_minute' => 1]);
    ListingFilterAgent::fake(array_fill(0, 4, nlEmptyFilter()));

    foreach (['a', 'b', 'c'] as $q) {
        $this->getJson('/api/listings/nl?q='.$q)->assertOk();
    }
    $this->getJson('/api/listings/nl?q=d&provider=fake-instant')->assertOk();
});

it('AC-7 a refused provider is not counted against the cloud limit', function (): void {
    config(['ai.cloud_per_minute' => 1, 'ai.providers.openai.key' => null]);
    ListingFilterAgent::fake([nlEmptyFilter()]);

    $this->getJson('/api/listings/nl?q=a&provider=openai')->assertStatus(422);
    $this->getJson('/api/listings/nl?q=a&provider=openai')->assertStatus(422);
    $this->getJson('/api/listings/nl?q=a&provider=anthropic')->assertOk();
});

it('NFR-3 every cloud entry carries max_tokens and the agent applies it', function (): void {
    foreach (['anthropic', 'openai', 'gemini'] as $name) {
        expect(config("ai.providers.{$name}.max_tokens"))->toBeInt()->toBeGreaterThan(0);
    }

    config(['ai.providers.anthropic.max_tokens' => 123]);

    expect((new ListingFilterAgent)->usingProvider('anthropic')->maxTokens())->toBe(123);
});

it('MNC-2 AC-3 a non-string provider answers 422 before the cloud limiter reads it', function (string $method, string $uri, array $payload): void {
    config(['ai.cloud_per_minute' => 1]);
    $listing = Listing::factory()->for(Dealer::factory())->create();
    Http::fake();

    $send = fn (mixed $provider) => $this->json(
        $method,
        str_replace('{id}', (string) $listing->id, $uri),
        $payload + ['provider' => $provider],
    );

    $send(['x'])->assertStatus(422)->assertJsonValidationErrors('provider');
    $send(5)->assertStatus(422)->assertJsonValidationErrors('provider');

    expect(AiRun::count())->toBe(0);
    Http::assertNothingSent();

    config(['ai.nl_search.per_minute' => 100]);
    ListingFilterAgent::fake([nlEmptyFilter()]);
    $this->getJson('/api/listings/nl?q=a&provider=anthropic')->assertOk();
})->with([
    'nl search' => ['GET', '/api/listings/nl', ['q' => 'a']],
    'describe' => ['GET', '/listings/{id}/describe', []],
    'docs ask' => ['POST', '/api/docs/ask', ['question' => 'How does it work?']],
]);

it('AC-7 NFR-3 defaults the cloud limit to 20 per minute when AI_CLOUD_PER_MINUTE is unset', function (): void {
    putenv('AI_CLOUD_PER_MINUTE');
    unset($_ENV['AI_CLOUD_PER_MINUTE'], $_SERVER['AI_CLOUD_PER_MINUTE']);

    $config = require config_path('ai.php');

    expect($config['cloud_per_minute'])->toBe(20)
        ->and(file_get_contents(base_path('.env.example')))->toContain('# AI_CLOUD_PER_MINUTE=20');
});
