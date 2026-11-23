<?php

declare(strict_types=1);

use App\Ai\AiProviders;
use App\Ai\AiRunRecorder;
use App\Ai\FallbackPolicy;
use App\Ai\ProviderChoice;
use App\DTOs\ModerationInput;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\DocsDocument;
use App\Models\Listing;
use App\Services\ModerationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\StreamErrorException;

beforeEach(function (): void {
    config(['ai.default' => 'ollama', 'ai.features.moderation.fallback_model' => 'qwen3:0.6b']);
});

it('AC-5 defaults the fallback to qwen3:0.6b for the four non-moderation local features', function (): void {
    foreach (['describe', 'nl-search', 'docs-answer', 'assistant'] as $feature) {
        $policy = (new AiProviders)->fallbackPolicy($feature);

        expect($policy)->toBeInstanceOf(FallbackPolicy::class)
            ->and($policy->enabled())->toBeTrue()
            ->and($policy->model)->toBe('qwen3:0.6b')
            ->and($policy->afterMs)->toBeNull();
    }
});

it('C-14 keeps the moderation fallback off by default until its eval and threshold exist', function (): void {
    $config = require base_path('config/ai.php');

    expect($config['features']['moderation']['fallback_model'])->toBeNull()
        ->and(array_key_exists('fallback_after_ms', $config['features']['moderation']))->toBeFalse()
        ->and(array_key_exists('fallback_after_ms', $config['features']['nl-search']))->toBeFalse();
});

it('AC-5 ignores a first-token budget on the non-streamed features', function (): void {
    config(['ai.features.nl-search.fallback_after_ms' => 500, 'ai.features.moderation.fallback_after_ms' => 500]);

    expect((new AiProviders)->fallbackPolicy('nl-search')->afterMs)->toBeNull()
        ->and((new AiProviders)->fallbackPolicy('moderation')->afterMs)->toBeNull();
});

it('AC-1 maps a bare stream-open failure, a stream error and a refused connection to their reasons', function (): void {
    $bare = new ProviderConnectionException('HTTP request failed!', previous: new ConnectionException('Error creating resource: HTTP request failed!'));

    expect(FallbackPolicy::reasonFor($bare))->toBe('timeout')
        ->and(FallbackPolicy::reasonFor(new StreamErrorException))->toBe('server_error')
        ->and(FallbackPolicy::reasonFor(new ConnectionException('cURL error 7: Failed to connect: Connection refused')))->toBe('unreachable');
});

it('AC-5 keeps the fallback off for the listed features', function (): void {
    foreach (['research', 'rag-grader', 'photo-vision', 'admin-assistant'] as $feature) {
        expect((new AiProviders)->fallbackPolicy($feature)->enabled())->toBeFalse();
    }
});

it('AC-5 and MNC-1 turns the fallback off when the model is empty', function (): void {
    config(['ai.features.describe.fallback_model' => null]);
    expect((new AiProviders)->fallbackPolicy('describe')->enabled())->toBeFalse();

    config(['ai.features.describe.fallback_model' => '']);
    expect((new AiProviders)->fallbackPolicy('describe'))->toEqual(FallbackPolicy::off());
});

it('AC-5 reads an overridden model and budget from configuration', function (): void {
    config([
        'ai.features.describe.fallback_model' => 'gemma4:12b',
        'ai.features.describe.fallback_after_ms' => 2000,
    ]);

    $policy = (new AiProviders)->fallbackPolicy('describe');

    expect($policy->model)->toBe('gemma4:12b')->and($policy->afterMs)->toBe(2000);
});

it('AC-5 ignores a budget of zero or less', function (): void {
    config(['ai.features.describe.fallback_after_ms' => 0]);

    expect((new AiProviders)->fallbackPolicy('describe')->afterMs)->toBeNull();
});

it('AC-5 and MNC-1 is off when the fallback model equals the effective main model', function (): void {
    config(['ai.features.describe.model' => 'qwen3:0.6b']);

    expect((new AiProviders)->fallbackPolicy('describe')->enabled())->toBeFalse();
});

it('AC-5 compares with the model the request resolved, not the configured one', function (): void {
    $main = new ProviderChoice('ollama', 'qwen3:0.6b', 'ollama');

    expect((new AiProviders)->fallbackPolicy('describe', $main)->enabled())->toBeFalse()
        ->and((new AiProviders)->fallbackPolicy('describe', new ProviderChoice('ollama', 'gemma4:12b', 'ollama'))->model)->toBe('qwen3:0.6b');
});

it('AC-5 and MNC-3 never falls back on a cloud provider', function (): void {
    config(['ai.providers.anthropic.key' => 'k', 'ai.features.describe.provider' => 'anthropic']);

    expect((new AiProviders)->fallbackPolicy('describe')->enabled())->toBeFalse();
});

it('AC-5 and MNC-3 never falls back on a fake provider', function (): void {
    config(['ai.default' => 'fake-stream']);

    expect((new AiProviders)->fallbackPolicy('describe')->enabled())->toBeFalse();
});

it('AC-1 a streamed run answered by the fallback records the main model and the reason', function (): void {
    $recorder = app(AiRunRecorder::class);
    $recorder->started('inv-fb', 'CarDescriptionAgent', 'ollama', 'qwen3:0.6b', 'car-description@v1');
    $recorder->fallbackFrom('inv-fb', 'qwen3.5:9b', 'busy');
    $recorder->completed('inv-fb', 10, 20);

    $run = AiRun::where('invocation_id', 'inv-fb')->firstOrFail();
    expect($run->model)->toBe('qwen3:0.6b')
        ->and($run->fallback_from_model)->toBe('qwen3.5:9b')
        ->and($run->fallback_reason)->toBe('busy');
});

it('AC-1 a fallback noted before the run starts is written with its row', function (): void {
    $recorder = app(AiRunRecorder::class);
    $recorder->fallbackFrom('inv-early', 'qwen3.5:9b', 'timeout');
    $recorder->started('inv-early', 'CarDescriptionAgent', 'ollama', 'qwen3:0.6b', null);
    $recorder->completed('inv-early', null, null);

    expect(AiRun::where('invocation_id', 'inv-early')->firstOrFail()->fallback_reason)->toBe('timeout');
});

it('AC-1 a non-streamed run records the fallback', function (): void {
    $id = app(AiRunRecorder::class)->recordRun(
        'completed', 'NlSearchAgent', 'ollama', 'qwen3:0.6b', 'nl-search@v1', 120, null,
        fallbackFromModel: 'qwen3.5:9b', fallbackReason: 'unreachable',
    );

    $run = AiRun::where('invocation_id', $id)->firstOrFail();
    expect($run->fallback_from_model)->toBe('qwen3.5:9b')
        ->and($run->fallback_reason)->toBe('unreachable');
});

it('AC-4 a failed row can name both models', function (): void {
    $recorder = app(AiRunRecorder::class);
    $recorder->started('inv-both', 'CarDescriptionAgent', 'ollama', 'qwen3:0.6b', null);
    $recorder->fallbackFrom('inv-both', 'qwen3.5:9b', 'server_error');
    $recorder->failed('inv-both', new RuntimeException('both down'));

    $run = AiRun::where('invocation_id', 'inv-both')->firstOrFail();
    expect($run->status)->toBe('failed')
        ->and($run->model)->toBe('qwen3:0.6b')
        ->and($run->fallback_from_model)->toBe('qwen3.5:9b')
        ->and($run->fallback_reason)->toBe('server_error');
});

it('MNC-1 a run without a fallback keeps both columns null', function (): void {
    $recorder = app(AiRunRecorder::class);
    $recorder->started('inv-plain', 'CarDescriptionAgent', 'ollama', 'qwen3.5:9b', null);
    $recorder->completed('inv-plain', 1, 2);
    $id = $recorder->recordRun('completed', 'NlSearchAgent', 'ollama', 'qwen3.5:9b', null, 5, null);

    expect(AiRun::where('invocation_id', 'inv-plain')->firstOrFail()->fallback_from_model)->toBeNull()
        ->and(AiRun::where('invocation_id', $id)->firstOrFail()->fallback_reason)->toBeNull();
});

it('AC-1 the fallback columns migrate down and up again', function (): void {
    Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

    expect(Schema::hasColumns('ai_runs', ['fallback_from_model', 'fallback_reason']))->toBeFalse();

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasColumns('ai_runs', ['fallback_from_model', 'fallback_reason']))->toBeTrue();
});

const FALLBACK_MAIN = 'qwen3.5:9b';
const FALLBACK_SMALL = 'qwen3:0.6b';
const FALLBACK_NL = '{"make":"BMW","model":null,"city":null,"fuel":null,"min_year":null,"max_price_eur":null,"unsupported":[]}';
const FALLBACK_MODERATION = '{"risk":0.1,"reasons":[],"explanation":"Looks like an ordinary listing."}';

/**
 * Fakes Ollama's chat endpoint per model. A behaviour is "ok", "unreachable", "timeout", "missing" (404), "error" (500)
 * "slow" (answers after 300 ms) or "after_token" (one token, then a failure line). Streamed requests answer with the stream text, structured ones
 * with the JSON text.
 *
 * @param  array<string, string>  $behaviour  model => behaviour
 */
function fallbackOllama(array $behaviour, string $text = 'A fine car', string $json = FALLBACK_NL): void
{
    config(['ai.default' => 'ollama']);
    Http::preventStrayRequests();
    $seen = new ArrayObject;
    $bodies = new ArrayObject;
    app()->instance('fallback.seen', $seen);
    app()->instance('fallback.bodies', $bodies);
    Http::fake(['*/api/chat' => function (Request $request, array $options) use ($behaviour, $text, $json, $seen, $bodies) {
        $body = $request->data();
        $model = (string) $body['model'];
        $seen[] = $model;
        $kind = $behaviour[$model] ?? 'ok';
        $bodies[] = $body;

        if (str_starts_with($kind, 'tool_then_')) {
            $kind = count(array_filter($seen->getArrayCopy(), fn (string $seenModel): bool => $seenModel === $model)) === 1 ? 'tool_call' : substr($kind, 10);
        }

        if ($kind === 'slow') {
            usleep(300_000);
            $kind = 'ok';
        }

        if ($kind === 'silent') {
            $silentForMs = 2000;

            if (($options['timeout'] ?? 60) * 1000 < $silentForMs) {
                throw new ConnectionException('cURL error 28: Operation timed out after '.(int) (($options['timeout'] ?? 0) * 1000).' milliseconds with 0 bytes received');
            }

            $kind = 'ok';
        }

        $streaming = ($body['stream'] ?? true) !== false;
        $done = ['model' => $model, 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'prompt_eval_count' => 7, 'eval_count' => 5];

        return match ($kind) {
            'unreachable' => throw new ConnectionException('cURL error 7: Failed to connect to localhost port 11434: Connection refused'),
            'timeout' => throw new ConnectionException('cURL error 28: Operation timed out after 1000 milliseconds'),
            'missing' => Http::response(['error' => "model '{$model}' not found"], 404),
            'error' => Http::response('boom', 500),
            'tool_call' => Http::response(json_encode(['model' => $model, 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'SearchListings', 'arguments' => ['make' => 'BMW']]]]], 'done' => true] + $done)."\n", 200, ['Content-Type' => 'application/x-ndjson']),
            'error_after' => Http::response('boom', 500),
            'bad_request' => Http::response(['error' => 'bad request'], 400),
            'stream_error' => Http::response(json_encode(['error' => 'runner crashed'])."\n", 200, ['Content-Type' => 'application/x-ndjson']),
            'after_token' => Http::response(json_encode(['model' => $model, 'message' => ['role' => 'assistant', 'content' => 'Partial'], 'done' => false])."\n".json_encode(['error' => 'model crashed'])."\n", 200, ['Content-Type' => 'application/x-ndjson']),
            default => $streaming
                ? Http::response(json_encode(['model' => $model, 'message' => ['role' => 'assistant', 'content' => $text], 'done' => false])."\n".json_encode($done)."\n", 200, ['Content-Type' => 'application/x-ndjson'])
                : Http::response(['model' => $model, 'message' => ['role' => 'assistant', 'content' => $json]] + $done, 200),
        };
    }]);
}

/**
 * @return list<string> the models of the chat requests sent, in order
 */
function fallbackRequestedModels(): array
{
    return app('fallback.seen')->getArrayCopy();
}

function fallbackRun(): AiRun
{
    return AiRun::query()->where('agent', 'not like', 'embeddings:%')->sole();
}

function fallbackDescribe(): string
{
    $listing = Listing::factory()->for(Dealer::factory())->create();

    return test()->get('/listings/'.$listing->id.'/describe')->assertOk()->streamedContent();
}

function fallbackAssistant(): string
{
    return test()->postJson('/api/assistant/ask', ['question' => 'a cheap diesel'])->assertOk()->streamedContent();
}

function fallbackDocs(): string
{
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [1.0, ...array_fill(0, 1023, 0.0)], $prompt->inputs));
    $document = DocsDocument::create(['source' => 'laravel', 'path' => 'rate-limiting.md', 'title' => 'rate', 'content_hash' => hash('sha256', 'rate')]);
    $chunk = $document->chunks()->create(['position' => 0, 'heading' => 'Rate Limiting', 'body' => 'Limiters cap requests per minute for each key.', 'content' => "rate-limiting.md › Rate Limiting\n\nLimiters cap requests per minute for each key.", 'token_estimate' => 10, 'content_hash' => hash('sha256', 'body')]);
    DB::update('UPDATE docs_chunks SET embedding = CAST(? AS vector), embedding_model = ? WHERE id = ?', [json_encode([1.0, ...array_fill(0, 1023, 0.0)]), config('ai.default_for_embeddings').'/'.config('ai.providers.'.config('ai.default_for_embeddings').'.models.embeddings.default'), $chunk->id]);

    return test()->postJson('/api/docs/ask', ['q' => 'how does rate limiting work'])->assertOk()->streamedContent();
}

/**
 * @return array{0: string, 1: string} the stream text and the last event before [DONE]
 */
function fallbackErrorOf(string $body): array
{
    $lines = array_values(array_filter(explode("\n", $body), fn (string $line): bool => $line !== ''));

    return [$body, $lines[count($lines) - 2]];
}

function fallbackNl(): TestResponse
{
    return test()->getJson('/api/listings/nl?q=a+bmw');
}

function fallbackModerate(): TestResponse
{
    return test()->postJson('/api/moderation', ['title' => 'Golf 7', 'price_eur' => 5000, 'make' => 'Volkswagen', 'model' => 'Golf', 'year' => 2018, 'mileage_km' => 90000, 'city' => 'Berlin', 'description' => 'Well kept car, service book, two keys.']);
}

dataset('fallback triggers', [
    'unreachable' => ['unreachable', 'unreachable'],
    'model missing' => ['missing', 'model_missing'],
    'server error' => ['error', 'server_error'],
    'timeout' => ['timeout', 'timeout'],
]);

dataset('streamed fallback features', [
    'describe' => ['fallbackDescribe', 'CarDescriptionAgent'],
    'assistant' => ['fallbackAssistant', 'BuyerAssistantAgent'],
    'docs-answer' => ['fallbackDocs', 'DocsAnswerAgent'],
]);

it('AC-1 a streamed feature answers with the fallback model on every trigger and writes one row', function (string $ask, string $agent, string $kind, string $reason): void {
    fallbackOllama([FALLBACK_MAIN => $kind]);

    $body = $ask();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL])
        ->and($body)->toContain('A fine car')->not->toContain('"type":"error"');

    $run = fallbackRun();
    expect($run->agent)->toBe($agent)
        ->and($run->status)->toBe('completed')
        ->and($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe($reason)
        ->and($run->ttft_ms)->toBeInt()
        ->and($run->error_class)->toBeNull();
})->with('streamed fallback features', 'fallback triggers');

it('AC-1 a streamed fallback run keeps the latency of the whole request', function (): void {
    fallbackOllama([FALLBACK_MAIN => 'unreachable']);

    fallbackDescribe();

    $run = fallbackRun();
    expect($run->latency_ms)->toBeGreaterThanOrEqual($run->ttft_ms);
});

it('AC-1 nl-search answers with the fallback model on every trigger and writes one row', function (string $kind, string $reason): void {
    fallbackOllama([FALLBACK_MAIN => $kind]);

    $response = fallbackNl();

    $response->assertOk();
    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL]);

    $run = fallbackRun();
    expect($run->agent)->toBe('ListingFilterAgent')
        ->and($run->status)->toBe('completed')
        ->and($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe($reason);
})->with('fallback triggers');

it('AC-1 moderation answers with the fallback model on every trigger and writes one row', function (string $kind, string $reason): void {
    fallbackOllama([FALLBACK_MAIN => $kind], json: FALLBACK_MODERATION);

    $response = fallbackModerate();

    $response->assertOk();
    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL]);

    $run = fallbackRun();
    expect($run->agent)->toBe('ModerationAgent')
        ->and($run->status)->toBe('completed')
        ->and($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe($reason);
})->with('fallback triggers');

it('AC-3 a stream that already sent a token is not moved to the fallback model', function (string $ask): void {
    fallbackOllama([FALLBACK_MAIN => 'after_token']);

    [$body, $last] = fallbackErrorOf($ask());

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN])
        ->and($body)->toContain('Partial')
        ->and(json_decode(substr($last, 6), true)['type'])->toBe('error')
        ->and($body)->not->toContain('model crashed');

    $run = fallbackRun();
    expect($run->status)->toBe('failed')
        ->and($run->model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_from_model)->toBeNull()
        ->and($run->fallback_reason)->toBeNull();
})->with(['describe' => ['fallbackDescribe'], 'assistant' => ['fallbackAssistant'], 'docs-answer' => ['fallbackDocs']]);

it('AC-3 and MNC-3 a tool call already made counts as a sent token, so the assistant does not fall back', function (string $failure): void {
    fallbackOllama([FALLBACK_MAIN => 'tool_then_'.$failure]);

    [$body, $last] = fallbackErrorOf(fallbackAssistant());

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_MAIN])
        ->and(json_decode(substr($last, 6), true)['type'])->toBe('error')
        ->and($body)->not->toContain('boom')->not->toContain('refused');

    $toolMessages = array_filter(app('fallback.bodies')->getArrayCopy()[1]['messages'], fn (array $message): bool => $message['role'] === 'tool');
    expect($toolMessages)->toHaveCount(1);

    $run = fallbackRun();
    expect($run->status)->toBe('failed')
        ->and($run->model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_from_model)->toBeNull()
        ->and($run->fallback_reason)->toBeNull();
})->with(['server error' => ['error_after'], 'connection error' => ['unreachable']]);

it('AC-4 a stream whose fallback fails too ends with the masked error and one failed row naming both models', function (string $ask): void {
    fallbackOllama([FALLBACK_MAIN => 'unreachable', FALLBACK_SMALL => 'error']);

    [$body, $last] = fallbackErrorOf($ask());

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL])
        ->and(json_decode(substr($last, 6), true)['type'])->toBe('error')
        ->and($body)->not->toContain('boom')->not->toContain('refused');

    $run = fallbackRun();
    expect($run->status)->toBe('failed')
        ->and($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe('unreachable');
})->with(['describe' => ['fallbackDescribe'], 'assistant' => ['fallbackAssistant'], 'docs-answer' => ['fallbackDocs']]);

it('AC-4 nl-search whose fallback fails too answers 503 as today and writes one failed row naming both models', function (): void {
    fallbackOllama([FALLBACK_MAIN => 'unreachable', FALLBACK_SMALL => 'unreachable']);

    $response = fallbackNl();

    $response->assertStatus(503);
    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL]);

    $run = fallbackRun();
    expect($run->status)->toBe('failed')
        ->and($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe('unreachable');
});

it('AC-4 moderation whose fallback fails too answers 503 as today and writes one failed row naming both models', function (): void {
    fallbackOllama([FALLBACK_MAIN => 'timeout', FALLBACK_SMALL => 'error'], json: FALLBACK_MODERATION);

    fallbackModerate()->assertStatus(503);

    $run = fallbackRun();
    expect($run->status)->toBe('failed')
        ->and($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe('timeout');
});

it('MNC-1 without a fallback model a failing main model fails as today, with its own model in one row', function (string $ask): void {
    foreach (['describe', 'assistant', 'docs-answer', 'nl-search', 'moderation'] as $feature) {
        config(["ai.features.{$feature}.fallback_model" => null]);
    }
    fallbackOllama([FALLBACK_MAIN => 'unreachable']);

    $ask();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN]);

    $run = fallbackRun();
    expect($run->status)->toBe('failed')
        ->and($run->model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_from_model)->toBeNull()
        ->and($run->fallback_reason)->toBeNull();
})->with(['describe' => ['fallbackDescribe'], 'assistant' => ['fallbackAssistant'], 'docs-answer' => ['fallbackDocs']]);

it('MNC-1 nl-search and moderation without a fallback model fail as today', function (): void {
    config(['ai.features.nl-search.fallback_model' => '', 'ai.features.moderation.fallback_model' => '']);
    fallbackOllama([FALLBACK_MAIN => 'error'], json: FALLBACK_MODERATION);

    fallbackNl()->assertStatus(503);
    fallbackModerate()->assertStatus(503);

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_MAIN]);
    expect(AiRun::query()->whereNotNull('fallback_reason')->count())->toBe(0);
});

it('MNC-3 an error that is not a trigger does not fall back', function (): void {
    fallbackOllama([FALLBACK_MAIN => 'bad_request']);

    fallbackNl()->assertStatus(503);

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN]);
    expect(fallbackRun()->fallback_reason)->toBeNull();
});

it('MNC-3 a fallback answer that breaks the listing filter rules fails like an invalid main answer', function (): void {
    $invalid = str_replace('"min_year":null', '"min_year":1700', FALLBACK_NL);
    fallbackOllama([FALLBACK_MAIN => 'unreachable'], json: $invalid);

    fallbackNl()->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('status', 422)
        ->assertJsonStructure(['errors' => ['min_year'], 'extracted', 'unsupported', 'invocation_id'])
        ->assertJsonPath('extracted.min_year', 1700);

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL]);
    $run = fallbackRun();
    expect($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe('unreachable')
        ->and($run->validation_failed)->toBeTrue();
});

it('MNC-3 a cloud provider never falls back to a local model', function (): void {
    config(['ai.providers.anthropic.key' => 'k', 'ai.features.describe.provider' => 'anthropic']);
    Http::preventStrayRequests();
    $urls = new ArrayObject;
    Http::fake(['*' => function (Request $request) use ($urls): never {
        $urls[] = $request->url();

        throw new ConnectionException('cURL error 7: refused');
    }]);

    fallbackDescribe();

    expect($urls)->toHaveCount(1)
        ->and($urls[0])->not->toContain('11434');
    expect(fallbackRun()->fallback_reason)->toBeNull();
});

it('MNC-3 the fake provider never falls back', function (): void {
    config(['ai.default' => 'fake-stream']);
    Http::preventStrayRequests();

    fallbackDescribe();

    Http::assertNothingSent();
    expect(fallbackRun()->fallback_reason)->toBeNull();
});

it('MNC-3 a model pinned by the moderation eval does not fall back', function (): void {
    fallbackOllama([FALLBACK_MAIN => 'unreachable'], json: FALLBACK_MODERATION);

    $result = app(ModerationService::class)->moderate(new ModerationInput('Golf', 5000.0, 'Volkswagen', 'Golf', 2018, 90000, 'Berlin', 'Well kept car.'), null, FALLBACK_MAIN);

    expect($result->status)->toBe('failed')
        ->and(fallbackRequestedModels())->toBe([FALLBACK_MAIN]);
});

it('AC-1 a fallback answer is not stored in the docs answer cache', function (): void {
    config(['ai.docs.cache.enabled' => true]);
    fallbackOllama([FALLBACK_MAIN => 'unreachable']);

    fallbackDocs();

    expect(DB::table('docs_answer_cache')->count())->toBe(0);
});

it('AC-2 a main model with no first token within the budget is cancelled and the fallback answers, reason busy', function (string $ask, string $agent): void {
    config([
        'ai.features.describe.fallback_after_ms' => 100,
        'ai.features.assistant.fallback_after_ms' => 100,
        'ai.features.docs-answer.fallback_after_ms' => 100,
    ]);
    fallbackOllama([FALLBACK_MAIN => 'slow']);

    $body = $ask();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL])
        ->and($body)->toContain('A fine car')->not->toContain('"type":"error"');

    $run = fallbackRun();
    expect($run->agent)->toBe($agent)
        ->and($run->status)->toBe('completed')
        ->and($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe('busy')
        ->and($run->latency_ms)->toBeGreaterThanOrEqual(100);
})->with('streamed fallback features');

it('AC-2 and NFR-2 a first token within the budget does not fall back', function (string $ask): void {
    config([
        'ai.features.describe.fallback_after_ms' => 5000,
        'ai.features.assistant.fallback_after_ms' => 5000,
        'ai.features.docs-answer.fallback_after_ms' => 5000,
    ]);
    fallbackOllama([]);

    $body = $ask();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN])
        ->and($body)->toContain('A fine car');

    $run = fallbackRun();
    expect($run->model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_from_model)->toBeNull()
        ->and($run->fallback_reason)->toBeNull();
})->with(['describe' => ['fallbackDescribe'], 'assistant' => ['fallbackAssistant'], 'docs-answer' => ['fallbackDocs']]);

it('AC-2 and NFR-2 without a budget a slow main model is waited for', function (): void {
    fallbackOllama([FALLBACK_MAIN => 'slow']);

    fallbackDescribe();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN])
        ->and(fallbackRun()->fallback_reason)->toBeNull();
});

it('AC-2 a slow main model with the fallback off is waited for', function (): void {
    config(['ai.features.describe.fallback_after_ms' => 100, 'ai.features.describe.fallback_model' => '']);
    fallbackOllama([FALLBACK_MAIN => 'slow']);

    fallbackDescribe();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN])
        ->and(fallbackRun()->fallback_from_model)->toBeNull();
});

it('AC-2 a slow main model whose fallback fails too ends with the masked error and one failed row', function (): void {
    config(['ai.features.describe.fallback_after_ms' => 100]);
    fallbackOllama([FALLBACK_MAIN => 'slow', FALLBACK_SMALL => 'error']);

    [$body, $last] = fallbackErrorOf(fallbackDescribe());

    expect(json_decode(substr($last, 6), true)['type'])->toBe('error');

    $run = fallbackRun();
    expect($run->status)->toBe('failed')
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe('busy');
});

it('AC-2 a main model silent past the budget fails at the first byte, not after it would have answered, reason busy', function (): void {
    config(['ai.features.describe.fallback_after_ms' => 100]);
    fallbackOllama([FALLBACK_MAIN => 'silent']);

    $body = fallbackDescribe();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL])
        ->and($body)->toContain('A fine car')->not->toContain('"type":"error"');

    $run = fallbackRun();
    expect($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe('busy')
        ->and($run->latency_ms)->toBeLessThan(1000);
});

it('AC-2 the first-byte budget applies to the main model only, not to the fallback attempt', function (): void {
    config(['ai.features.describe.fallback_after_ms' => 100]);
    fallbackOllama([FALLBACK_MAIN => 'silent', FALLBACK_SMALL => 'silent']);

    $body = fallbackDescribe();

    expect($body)->toContain('A fine car')->not->toContain('"type":"error"')
        ->and(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL])
        ->and(fallbackRun()->fallback_reason)->toBe('busy');
});

it('AC-2 a main model silent on a real loopback socket is cut at the first-byte budget and the fallback answers, reason busy', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    expect($server)->not->toBeFalse();
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

    config([
        'ai.default' => 'ollama',
        'ai.providers.ollama.url' => 'http://127.0.0.1:'.$port,
        'ai.features.describe.fallback_after_ms' => 100,
    ]);

    $seen = [];
    $done = ['model' => FALLBACK_SMALL, 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'prompt_eval_count' => 7, 'eval_count' => 5];
    Http::preventStrayRequests();
    Http::allowStrayRequests(['http://127.0.0.1:'.$port.'/*']);
    Http::fake(['*/api/chat' => function (Request $request) use (&$seen, $done) {
        $model = (string) $request->data()['model'];
        $seen[] = $model;

        if ($model === FALLBACK_MAIN) {
            return null;
        }

        return Http::response(
            json_encode(['model' => $model, 'message' => ['role' => 'assistant', 'content' => 'A fine car'], 'done' => false])."\n".json_encode($done)."\n",
            200,
            ['Content-Type' => 'application/x-ndjson'],
        );
    }]);

    $started = microtime(true);
    $body = fallbackDescribe();
    $elapsedMs = (microtime(true) - $started) * 1000;
    fclose($server);

    expect($seen)->toBe([FALLBACK_MAIN, FALLBACK_SMALL])
        ->and($body)->toContain('A fine car')->not->toContain('"type":"error"')
        ->and($elapsedMs)->toBeLessThan(1000);

    $run = fallbackRun();
    expect($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_from_model)->toBe(FALLBACK_MAIN)
        ->and($run->fallback_reason)->toBe('busy')
        ->and($run->latency_ms)->toBeLessThan(1000);
});

it('AC-1 an in-stream error before any content moves the stream to the fallback model', function (string $ask): void {
    fallbackOllama([FALLBACK_MAIN => 'stream_error']);

    $body = $ask();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL])
        ->and($body)->toContain('A fine car')->not->toContain('"type":"error"')->not->toContain('runner crashed');

    $run = fallbackRun();
    expect($run->status)->toBe('completed')
        ->and($run->model)->toBe(FALLBACK_SMALL)
        ->and($run->fallback_reason)->toBe('server_error');
})->with(['describe' => ['fallbackDescribe'], 'assistant' => ['fallbackAssistant'], 'docs-answer' => ['fallbackDocs']]);

it('C-10 a moderation retry after a fallback goes straight to the fallback model and names it', function (): void {
    fallbackOllama([FALLBACK_MAIN => 'timeout'], json: '{"risk":7,"reasons":[],"explanation":"x"}');

    $response = fallbackModerate();

    expect(fallbackRequestedModels())->toBe([FALLBACK_MAIN, FALLBACK_SMALL, FALLBACK_SMALL]);

    $rows = AiRun::query()->where('agent', 'ModerationAgent')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('model')->unique()->all())->toBe([FALLBACK_SMALL])
        ->and($rows->pluck('fallback_reason')->unique()->all())->toBe(['timeout'])
        ->and($response->json('data.model') ?? $response->json('model'))->toBe(FALLBACK_SMALL);
});
