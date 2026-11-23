<?php

declare(strict_types=1);

use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\DocsDocument;
use App\Models\Listing;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->withoutVite();
    Cache::flush();
    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.settings_ui' => true]);
    Http::fake(['*/api/tags' => Http::response(['models' => []])]);
});

function labFallbackRun(array $attributes = []): AiRun
{
    return AiRun::create([
        'invocation_id' => 'run-fb',
        'agent' => 'CarDescriptionAgent',
        'provider' => 'ollama',
        'model' => 'qwen3:0.6b',
        'latency_ms' => 900,
        'status' => 'completed',
        ...$attributes,
    ]);
}

it('AC-6 the run endpoint says which fallback model answered and why', function (): void {
    labFallbackRun(['fallback_from_model' => 'qwen3.5:9b', 'fallback_reason' => 'model_missing']);

    $this->getJson('/ai/runs/run-fb')
        ->assertOk()
        ->assertJson(['model' => 'qwen3:0.6b', 'fallback_from_model' => 'qwen3.5:9b', 'fallback_reason' => 'model_missing']);
});

it('AC-6 a run the main model answered carries no fallback', function (): void {
    labFallbackRun();

    $this->getJson('/ai/runs/run-fb')
        ->assertOk()
        ->assertJson(['fallback_from_model' => null, 'fallback_reason' => null]);
});

it('AC-6 the lab overview lists the fallback of recent runs', function (): void {
    labFallbackRun(['fallback_from_model' => 'qwen3.5:9b', 'fallback_reason' => 'busy']);

    $this->get('/ai')->assertInertia(fn (Assert $page) => $page
        ->where('recentRuns.0.fallback_from_model', 'qwen3.5:9b')
        ->where('recentRuns.0.fallback_reason', 'busy'));
});

it('AC-6 the settings page lists each feature fallback model and budget', function (): void {
    config([
        'ai.default' => 'ollama',
        'ai.features.describe.provider' => null,
        'ai.features.describe.model' => 'qwen3.5:9b',
        'ai.features.describe.fallback_model' => 'qwen3:0.6b',
        'ai.features.describe.fallback_after_ms' => 1500,
        'ai.features.nl-search.provider' => null,
        'ai.features.nl-search.model' => 'qwen3.5:9b',
        'ai.features.nl-search.fallback_model' => 'qwen3:0.6b',
        'ai.features.nl-search.fallback_after_ms' => null,
    ]);

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows', fn ($rows) => collect($rows)->firstWhere('feature', 'describe')['fallback'] === ['model' => 'qwen3:0.6b', 'afterMs' => 1500]
            && collect($rows)->firstWhere('feature', 'nl-search')['fallback'] === ['model' => 'qwen3:0.6b', 'afterMs' => null]));
});

it('AC-6 the settings page shows no fallback for a cloud provider or an embeddings feature', function (): void {
    config([
        'ai.default' => 'anthropic',
        'ai.providers.anthropic.key' => 'sk-test',
        'ai.features.describe.provider' => null,
        'ai.features.describe.model' => null,
    ]);

    $this->get('/ai/settings')->assertInertia(fn (Assert $page) => $page
        ->where('rows', fn ($rows) => collect($rows)->firstWhere('feature', 'describe')['fallback'] === null
            && collect($rows)->firstWhere('feature', 'embeddings-listings')['fallback'] === null));
});

function labFallbackStream(): void
{
    Http::preventStrayRequests();
    Http::fake(['*/api/chat' => function (Request $request) {
        $model = (string) $request->data()['model'];

        if ($model === 'qwen3.5:9b') {
            throw new ConnectionException('cURL error 7: Failed to connect to localhost port 11434: Connection refused');
        }

        $done = ['model' => $model, 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'prompt_eval_count' => 7, 'eval_count' => 5];

        return Http::response(
            json_encode(['model' => $model, 'message' => ['role' => 'assistant', 'content' => 'A fine car'], 'done' => false])."\n".json_encode($done)."\n",
            200,
            ['Content-Type' => 'application/x-ndjson'],
        );
    }]);
}

function labFallbackDescribe(): string
{
    $listing = Listing::factory()->for(Dealer::factory())->create();

    return test()->get('/listings/'.$listing->id.'/describe')->assertOk()->streamedContent();
}

function labFallbackAssistant(): string
{
    return test()->postJson('/api/assistant/ask', ['question' => 'a cheap diesel'])->assertOk()->streamedContent();
}

function labFallbackDocs(): string
{
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [1.0, ...array_fill(0, 1023, 0.0)], $prompt->inputs));
    $document = DocsDocument::create(['source' => 'laravel', 'path' => 'rate-limiting.md', 'title' => 'rate', 'content_hash' => hash('sha256', 'rate')]);
    $chunk = $document->chunks()->create(['position' => 0, 'heading' => 'Rate Limiting', 'body' => 'Limiters cap requests per minute for each key.', 'content' => "rate-limiting.md › Rate Limiting\n\nLimiters cap requests per minute for each key.", 'token_estimate' => 10, 'content_hash' => hash('sha256', 'body')]);
    DB::update('UPDATE docs_chunks SET embedding = CAST(? AS vector), embedding_model = ? WHERE id = ?', [json_encode([1.0, ...array_fill(0, 1023, 0.0)]), config('ai.default_for_embeddings').'/'.config('ai.providers.'.config('ai.default_for_embeddings').'.models.embeddings.default'), $chunk->id]);

    return test()->postJson('/api/docs/ask', ['q' => 'how does rate limiting work'])->assertOk()->streamedContent();
}

it('AC-6 the invocation id a streamed feature page reads is the fallback row id', function (string $ask): void {
    config(['ai.default' => 'ollama']);
    labFallbackStream();

    $body = $ask();

    $ids = collect(explode("\n", $body))
        ->filter(fn (string $line): bool => str_starts_with($line, 'data: {'))
        ->map(fn (string $line): array => json_decode(substr($line, 6), true))
        ->pluck('invocation_id')
        ->filter()
        ->values();

    $run = AiRun::query()->where('agent', 'not like', 'embeddings:%')->sole();

    expect($ids)->not->toBeEmpty()
        ->and($ids->first())->toBe($run->invocation_id);

    $this->getJson('/ai/runs/'.$ids->first())
        ->assertOk()
        ->assertJson(['model' => 'qwen3:0.6b', 'fallback_from_model' => 'qwen3.5:9b', 'fallback_reason' => 'unreachable']);
})->with([
    'describe' => ['labFallbackDescribe'],
    'assistant' => ['labFallbackAssistant'],
    'docs-answer' => ['labFallbackDocs'],
]);
