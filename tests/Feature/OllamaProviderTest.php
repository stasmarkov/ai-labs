<?php

declare(strict_types=1);

use App\Ai\CarDescriptionAgent;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;
use App\Services\ListingDescriptionService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeOllamaChat(): void
{
    Http::preventStrayRequests();
    Http::fake([
        '*/api/chat' => fn () => Http::response(
            json_encode(['model' => 'qwen3.5:9b', 'message' => ['role' => 'assistant', 'content' => 'Nice car'], 'done' => false])."\n"
            .json_encode(['model' => 'qwen3.5:9b', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'prompt_eval_count' => 87, 'eval_count' => 142])."\n",
            200,
            ['Content-Type' => 'application/x-ndjson'],
        ),
    ]);
}

function describeListing(): void
{
    $listing = Listing::factory()->for(Dealer::factory())->create();

    test()->get('/listings/'.$listing->id.'/describe')->assertOk()->streamedContent();
}

/**
 * @return array<string, mixed>
 */
function sentOllamaBody(): array
{
    $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0])->filter(fn (Request $request): bool => str_ends_with($request->url(), '/api/chat'));
    expect($sent)->toHaveCount(1);

    return $sent->first()->data();
}

it('AC-1 the active provider comes from config', function (): void {
    expect(config('ai.default'))->toBe('fake-stream')
        ->and(config('ai.providers.ollama.driver'))->toBe('ollama')
        ->and(config('ai.providers.ollama.url'))->toBe('http://host.docker.internal:11434')
        ->and(config('ai.providers.ollama.models.text.default'))->toBe('qwen3.5:9b');

    Http::preventStrayRequests();
    Http::fake();
    describeListing();
    Http::assertNothingSent();

    config(['ai.default' => 'ollama', 'ai.providers.ollama.url' => 'http://ollama.test:11434']);
    fakeOllamaChat();
    describeListing();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://ollama.test:11434/api/chat');
});

it('AC-2 the agent sends the configured request options', function (): void {
    config(['ai.default' => 'ollama']);
    fakeOllamaChat();

    describeListing();

    $body = sentOllamaBody();
    expect($body['model'])->toBe('qwen3.5:9b')
        ->and($body['stream'])->toBeTrue()
        ->and($body['think'])->toBeFalse()
        ->and($body['keep_alive'])->toBe('10m')
        ->and($body['options']['num_ctx'])->toBe(8192)
        ->and($body['options']['num_predict'])->toBe(400);
});

it('AC-2 overriding the ollama config changes the request body', function (): void {
    config([
        'ai.default' => 'ollama',
        'ai.providers.ollama.models.text.default' => 'other:1b',
        'ai.providers.ollama.max_tokens' => 50,
        'ai.providers.ollama.request_options' => ['think' => true, 'keep_alive' => '1h', 'num_ctx' => 4096],
    ]);
    fakeOllamaChat();

    describeListing();

    $body = sentOllamaBody();
    expect($body['model'])->toBe('other:1b')
        ->and($body['think'])->toBeTrue()
        ->and($body['keep_alive'])->toBe('1h')
        ->and($body['options']['num_ctx'])->toBe(4096)
        ->and($body['options']['num_predict'])->toBe(50);
});

it('AC-2 the http timeout comes from the ollama entry and defaults to 120', function (): void {
    expect(config('ai.providers.ollama.timeout'))->toBe(120);

    config(['ai.default' => 'ollama']);

    $agent = new CarDescriptionAgent;
    expect($agent->timeout())->toBe(120);

    config(['ai.providers.ollama.timeout' => 30]);
    expect($agent->timeout())->toBe(30);
});

it('MNC-1 the fake-stream path sends no http request', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    describeListing();

    Http::assertNothingSent();
    expect((new CarDescriptionAgent)->timeout())->toBe(60)
        ->and((new CarDescriptionAgent)->maxTokens())->toBeNull();
});

it('AC-3 a streamed ollama run logs the model\'s token counts', function (): void {
    config(['ai.default' => 'ollama']);
    fakeOllamaChat();

    describeListing();

    expect(AiRun::count())->toBe(1);

    $run = AiRun::firstOrFail();
    expect($run->status)->toBe('completed')
        ->and($run->provider)->toBe('ollama')
        ->and($run->model)->toBe('qwen3.5:9b')
        ->and($run->tokens_in)->toBe(87)
        ->and($run->tokens_out)->toBe(142)
        ->and($run->ttft_ms)->toBeInt()
        ->and($run->latency_ms)->toBeGreaterThanOrEqual($run->ttft_ms)
        ->and($run->error_class)->toBeNull();
});

it('AC-4 an unreachable or failing ollama gives a failed run', function (string $kind): void {
    config(['ai.default' => 'ollama']);
    Http::preventStrayRequests();
    Http::fake([
        '*/api/chat' => $kind === 'connection'
            ? fn () => throw new ConnectionException('cURL error 7: connection refused')
            : Http::response('boom', 500),
    ]);
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $body = test()->get('/listings/'.$listing->id.'/describe')->streamedContent();

    $lines = array_values(array_filter(explode("\n", $body), fn (string $line): bool => $line !== ''));
    expect(end($lines))->toBe('data: [DONE]');

    $error = json_decode(substr($lines[count($lines) - 2], 6), true);
    expect($error['type'])->toBe('error')
        ->and($error['message'])->toBe('The description could not be generated.')
        ->and($body)->not->toContain('refused')->not->toContain('boom');

    $run = AiRun::firstOrFail();
    expect(AiRun::count())->toBe(1)
        ->and($run->status)->toBe('failed')
        ->and($run->provider)->toBe('ollama')
        ->and($run->error_class)->not->toBeNull()
        ->and($run->tokens_in)->toBeNull();
})->with(['connection', 'server error']);

it('AC-4 an unknown listing gives a JSON 404 and no http call', function (): void {
    config(['ai.default' => 'ollama']);
    Http::preventStrayRequests();
    Http::fake();

    test()->getJson('/listings/999999/describe')->assertNotFound();

    Http::assertNothingSent();
    expect(AiRun::count())->toBe(0);
});

it('AC-3 AC-7 ttft is recorded when the service outlives the scoped recorder', function (): void {
    config(['ai.default' => 'ollama']);
    fakeOllamaChat();
    app()->singleton(ListingDescriptionService::class);

    describeListing();
    app()->forgetScopedInstances();
    describeListing();

    expect(AiRun::count())->toBe(2)
        ->and(AiRun::whereNull('ttft_ms')->count())->toBe(0);
});

it('AC-2 a second ollama entry gets its own request options, max tokens and timeout', function (): void {
    config([
        'ai.default' => 'ollama-big',
        'ai.providers.ollama-big' => [
            'driver' => 'ollama',
            'url' => 'http://host.docker.internal:11434',
            'max_tokens' => 900,
            'timeout' => 30,
            'request_options' => ['think' => false, 'keep_alive' => -1, 'num_ctx' => 32768],
            'models' => ['text' => ['default' => 'qwen3.5:9b']],
        ],
    ]);
    fakeOllamaChat();
    describeListing();

    $body = sentOllamaBody();
    $agent = new CarDescriptionAgent;
    expect($body['options']['num_ctx'])->toBe(32768)
        ->and($body['options']['num_predict'])->toBe(900)
        ->and($body['keep_alive'])->toBe(-1)
        ->and($agent->timeout())->toBe(30)
        ->and($agent->providerOptions('anthropic'))->toBe([]);
});

it('AC-2 a unitless OLLAMA_KEEP_ALIVE is sent as a number', function (string $value, int|string $expected): void {
    $saved = [$_ENV['OLLAMA_KEEP_ALIVE'] ?? null, $_SERVER['OLLAMA_KEEP_ALIVE'] ?? null, getenv('OLLAMA_KEEP_ALIVE')];

    try {
        $_ENV['OLLAMA_KEEP_ALIVE'] = $_SERVER['OLLAMA_KEEP_ALIVE'] = $value;
        putenv('OLLAMA_KEEP_ALIVE='.$value);
        $config = require config_path('ai.php');
    } finally {
        [$env, $server, $process] = $saved;
        $_ENV['OLLAMA_KEEP_ALIVE'] = $env;
        $_SERVER['OLLAMA_KEEP_ALIVE'] = $server;
        putenv('OLLAMA_KEEP_ALIVE='.($process === false ? '' : $process));
    }

    expect($config['providers']['ollama']['request_options']['keep_alive'])->toBe($expected);
})->with([['-1', -1], ['3600', 3600], ['10m', '10m'], ['', '10m']]);

it('AC-4 describe is limited per IP and answers 429 as JSON', function (): void {
    config(['ai.describe_per_minute' => 2]);
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $this->get('/listings/'.$listing->id.'/describe')->assertOk()->streamedContent();
    $this->get('/listings/'.$listing->id.'/describe')->assertOk()->streamedContent();
    $limited = $this->get('/listings/'.$listing->id.'/describe');

    $limited->assertStatus(429);
    expect($limited->headers->get('Content-Type'))->toContain('application/json')
        ->and(AiRun::count())->toBe(2);
});

it('AC-4 describe 429 carries a numeric Retry-After and the same JSON body', function (): void {
    config(['ai.describe_per_minute' => 1]);
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $this->get('/listings/'.$listing->id.'/describe')->assertOk()->streamedContent();
    $limited = $this->get('/listings/'.$listing->id.'/describe');

    $limited->assertStatus(429);
    expect($limited->headers->get('Retry-After'))->toBeNumeric()
        ->and($limited->json())->toBe(['success' => false, 'status' => 429, 'message' => 'Too Many Attempts.', 'errors' => []]);
});
