<?php

declare(strict_types=1);

use App\Ai\AbortWatcher;
use App\Ai\AiRunRecorder;
use App\Ai\CanaryGenerator;
use App\Ai\DocsAnswerProtocol;
use App\Ai\FakeStreamProvider;
use App\Ai\OutputGuard;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\DocsDocument;
use App\Models\Listing;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports an abort once the given number of stream chunks has been flushed.
 */
function scAbortAfter(int $chunks): AbortWatcher
{
    return new class($chunks) implements AbortWatcher
    {
        private int $seen = 0;

        public function __construct(private readonly int $limit) {}

        public function aborted(): bool
        {
            return ++$this->seen >= $this->limit;
        }
    };
}

function scDocsChunk(): void
{
    $document = DocsDocument::create(['source' => 'repo', 'path' => 'rate-limiting.md', 'title' => 'rate-limiting.md', 'content_hash' => hash('sha256', 'rl')]);
    $chunk = $document->chunks()->create([
        'position' => 0,
        'heading' => 'Rate Limiting',
        'body' => 'Limiters cap requests per minute for each key.',
        'content' => "rate-limiting.md › Rate Limiting\n\nLimiters cap requests per minute for each key.",
        'token_estimate' => 10,
        'content_hash' => hash('sha256', 'body'),
    ]);

    DB::update(
        'UPDATE docs_chunks SET embedding = CAST(? AS vector), embedding_model = ? WHERE id = ?',
        [json_encode([1.0, ...array_fill(0, 1023, 0.0)]), config('ai.default_for_embeddings').'/'.config('ai.providers.'.config('ai.default_for_embeddings').'.models.embeddings.default'), $chunk->id],
    );
}

/**
 * Replaces the fake-stream gateway with one that counts the events it yields and records, in its finally block,
 * that the provider generator was closed and whether the response callback was still running at that moment.
 */
function scCountingStream(): stdClass
{
    $probe = new stdClass;
    $probe->read = 0;
    $probe->closed = false;
    $probe->abortIgnoredWhenClosed = 0;

    app(AiManager::class)->extend('fake-stream', function (Application $app, array $config) use ($probe): FakeStreamProvider {
        $provider = new FakeStreamProvider($config, $app->make(Dispatcher::class));
        $inner = $provider->textGateway();

        return $provider->useTextGateway(new class($inner, $probe) implements StepTextGateway
        {
            public function __construct(private readonly StepTextGateway $inner, private readonly stdClass $probe) {}

            public function generateTextStep(TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): StepResponse
            {
                return $this->inner->generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);
            }

            public function generateStreamStep(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options, ?int $timeout, StepContext $stepContext): Generator
            {
                try {
                    foreach ($this->inner->generateStreamStep($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext) as $event) {
                        $this->probe->read++;

                        yield $event;
                    }
                } finally {
                    $this->probe->closed = true;
                    $this->probe->abortIgnoredWhenClosed = ignore_user_abort();
                }
            }
        });
    });
    app(AiManager::class)->forgetInstance('fake-stream');

    return $probe;
}

/**
 * Reports an abort after the given number of flushed chunks and remembers how many it saw.
 */
function scCountingAbort(int $chunks): AbortWatcher
{
    return new class($chunks) implements AbortWatcher
    {
        public int $flushed = 0;

        public function __construct(private readonly int $limit) {}

        public function aborted(): bool
        {
            return ++$this->flushed >= $this->limit;
        }
    };
}

it('AC-1 a stopped describe stream writes a stopped row with the tokens sent and no further output', function (): void {
    app()->instance(AbortWatcher::class, scAbortAfter(5));
    $listing = Listing::factory()->for(Dealer::factory())->create(['make' => 'Land Rover', 'model' => 'Defender', 'year' => 2019]);

    $body = $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

    $run = AiRun::sole();
    expect(substr_count($body, '"type":"text_delta"'))->toBe(3)
        ->and($body)->not->toContain('[DONE]')
        ->and($body)->not->toContain('stream_end')
        ->and($run->status)->toBe('stopped')
        ->and($run->tokens_out)->toBe(3)
        ->and($run->ttft_ms)->not->toBeNull()
        ->and($run->stopped_after_ms)->not->toBeNull()
        ->and($run->stopped_after_ms)->toBeBetween($run->latency_ms - $run->ttft_ms - 1, $run->latency_ms - $run->ttft_ms + 1);
});

it('AC-1 a stream stopped before the first token has no TTFT, no stopped_after_ms and no tokens', function (): void {
    app()->instance(AbortWatcher::class, scAbortAfter(1));
    $listing = Listing::factory()->for(Dealer::factory())->create(['make' => 'Land Rover', 'model' => 'Defender', 'year' => 2019]);

    $body = $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

    $run = AiRun::sole();
    expect($body)->not->toContain('"type":"text_delta"')
        ->and($body)->not->toContain('[DONE]')
        ->and($run->status)->toBe('stopped')
        ->and($run->ttft_ms)->toBeNull()
        ->and($run->stopped_after_ms)->toBeNull()
        ->and($run->tokens_out)->toBeNull();
});

it('AC-1 AC-3 a stopped docs answer writes a stopped row and no cache entry', function (): void {
    config(['ai.docs.cache.enabled' => true, 'ai.docs.cache.store' => 'array', 'ai.providers.fake-stream.text' => 'Limiters cap requests per minute [1].']);
    app(AiManager::class)->forgetInstance('fake-stream');
    Cache::store('array')->flush();
    app()->instance(CanaryGenerator::class, new CanaryGenerator(fn (): string => 'ap-canary-0123456789abcdef'));
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [1.0, ...array_fill(0, 1023, 0.0)], $prompt->inputs));
    scDocsChunk();
    app()->instance(AbortWatcher::class, scAbortAfter(6));

    $body = $this->postJson('/api/docs/ask', ['q' => 'how does rate limiting work'])->streamedContent();

    $run = AiRun::where('agent', 'DocsAnswerAgent')->sole();
    expect(substr_count($body, '"type":"text_delta"'))->toBe(3)
        ->and($body)->not->toContain('"type":"citations"')
        ->and($body)->not->toContain('[DONE]')
        ->and($run->status)->toBe('stopped')
        ->and($run->tokens_out)->toBe(3)
        ->and($run->stopped_after_ms)->not->toBeNull()
        ->and(DB::table('docs_answer_cache')->count())->toBe(0);
});

it('MNC-2 a stream nobody stops ends as before and restores the abort setting', function (): void {
    $before = ignore_user_abort();
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $body = $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

    expect($body)->toEndWith("data: [DONE]\n\n")
        ->and(AiRun::sole()->status)->toBe('completed')
        ->and(ignore_user_abort())->toBe($before);
});

it('AC-1 an aborted describe stream reads at most one more model event and closes the provider generator', function (): void {
    $probe = scCountingStream();
    $watcher = scCountingAbort(5);
    app()->instance(AbortWatcher::class, $watcher);
    $listing = Listing::factory()->for(Dealer::factory())->create(['make' => 'Land Rover', 'model' => 'Defender', 'year' => 2019]);

    $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

    expect($probe->read)->toBeLessThanOrEqual($watcher->flushed + 1)
        ->and($probe->read)->toBeLessThan(20)
        ->and($probe->closed)->toBeTrue()
        ->and($probe->abortIgnoredWhenClosed)->toBe(1);
});

it('AC-1 an aborted docs answer reads at most one more model event and closes the provider generator', function (): void {
    config(['ai.docs.cache.enabled' => false, 'ai.providers.fake-stream.text' => 'Limiters cap requests per minute for each key and then keep going for a good while longer [1].']);
    $probe = scCountingStream();
    app()->instance(CanaryGenerator::class, new CanaryGenerator(fn (): string => 'ap-canary-0123456789abcdef'));
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [1.0, ...array_fill(0, 1023, 0.0)], $prompt->inputs));
    scDocsChunk();
    $watcher = scCountingAbort(6);
    app()->instance(AbortWatcher::class, $watcher);

    $this->postJson('/api/docs/ask', ['q' => 'how does rate limiting work'])->streamedContent();

    expect($probe->read)->toBeLessThanOrEqual($watcher->flushed + 1)
        ->and($probe->read)->toBeLessThan(18)
        ->and($probe->closed)->toBeTrue()
        ->and($probe->abortIgnoredWhenClosed)->toBe(1);
});

/**
 * Sends the protocol's response and returns what was written.
 */
function scSend(Response $response): string
{
    $body = '';
    ob_start(function (string $chunk) use (&$body): string {
        $body .= $chunk;

        return '';
    }, 1);
    $response->sendContent();
    ob_end_clean();

    return $body;
}

/**
 * A stream of the given events that counts how many of them were read, with its run already started in the recorder.
 *
 * @param  list<StreamEvent>  $events
 */
function scStream(array $events, stdClass $probe): StreamableAgentResponse
{
    $probe->read = 0;
    app(AiRunRecorder::class)->started('inv-sc', 'DocsAnswerAgent', 'fake-stream', 'fake-1', 'docs-answer@v1');

    return new StreamableAgentResponse('inv-sc', function () use ($events, $probe) {
        foreach ($events as $event) {
            $probe->read++;

            yield $event;
        }
    });
}

function scDocsProtocol(AbortWatcher $watcher, ?Closure $onCompleted = null, ?Closure $onStoppedBeforeRun = null): DocsAnswerProtocol
{
    return new DocsAnswerProtocol(app(AiRunRecorder::class), [], new OutputGuard('ap-canary-0123456789abcdef', 'You answer questions about the docs.', []), $onCompleted, $watcher, $onStoppedBeforeRun);
}

it('AC-1 a Stop during reasoning is noticed within one event and no reasoning is sent', function (): void {
    $probe = new stdClass;
    $events = [new StreamStart('s', 'fake', 'm', time()), new ReasoningStart('r0', 'r', time())];

    for ($i = 0; $i < 30; $i++) {
        $events[] = new ReasoningDelta('d'.$i, 'r', 'SECRET THOUGHT', time());
    }

    $events[] = new TextDelta('t', 'm', 'Answer', time());
    $watcher = scCountingAbort(6);

    $body = scSend(scDocsProtocol($watcher)->response(scStream($events, $probe)));

    $run = AiRun::sole();
    // chunk 1 is the sources event, then one comment per reasoning event (and the stream_start): the 6th flush aborts
    expect($watcher->flushed)->toBe(6)
        ->and($probe->read)->toBeLessThanOrEqual(6)
        ->and($body)->toContain(": keepalive\n\n")
        ->and($body)->not->toContain('SECRET THOUGHT')
        ->and($body)->not->toContain('reasoning')
        ->and($body)->not->toContain('[DONE]')
        ->and($run->status)->toBe('stopped')
        ->and($run->tokens_out)->toBeNull()
        ->and($run->ttft_ms)->toBeNull();
});

it('AC-1 a Stop noticed right after StreamEnd finishes the run as completed, not stopped, and stores nothing', function (): void {
    $probe = new stdClass;
    $events = [
        new StreamStart('s', 'fake', 'm', time()),
        new TextDelta('t', 'm', 'Answer text here', time()),
        new StreamEnd('e', 'stop', new TextUsage(12, 3), time()),
    ];
    $stored = false;
    // sources, stream_start, text_delta, stream_end: the 4th flush is the StreamEnd
    $body = scSend(scDocsProtocol(scCountingAbort(4), function () use (&$stored): void {
        $stored = true;
    })->response(scStream($events, $probe)));

    $run = AiRun::sole();
    expect($run->status)->toBe('completed')
        ->and($run->tokens_in)->toBe(12)
        ->and($run->tokens_out)->toBe(3)
        ->and($run->stopped_after_ms)->toBeNull()
        ->and($stored)->toBeFalse()
        ->and($body)->not->toContain('"type":"citations"');
});

it('AC-1 a Stop noticed right after the masked provider error finishes the run as failed, not stopped', function (): void {
    $probe = new stdClass;
    $events = [
        new StreamStart('s', 'fake', 'm', time()),
        new Error('e', 'server_error', 'Upstream secret detail', false, time()),
        new TextDelta('t', 'm', 'never read', time()),
    ];

    // sources, stream_start, masked error: the 3rd flush
    $body = scSend(scDocsProtocol(scCountingAbort(3))->response(scStream($events, $probe)));

    $run = AiRun::sole();
    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toBe('Upstream secret detail')
        ->and($body)->not->toContain('Upstream secret detail')
        ->and($body)->toContain('"type":"error"')
        ->and($body)->not->toContain('never read')
        ->and($probe->read)->toBe(2);
});

it('AC-1 a Stop at the very first flush, before the run started, still writes a stopped row with the request metadata', function (): void {
    config(['ai.docs.cache.enabled' => false]);
    app()->instance(CanaryGenerator::class, new CanaryGenerator(fn (): string => 'ap-canary-0123456789abcdef'));
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [1.0, ...array_fill(0, 1023, 0.0)], $prompt->inputs));
    scDocsChunk();
    $chunkId = (int) DB::table('docs_chunks')->value('id');
    app()->instance(AbortWatcher::class, scAbortAfter(1));
    $history = [['role' => 'user', 'content' => 'earlier'], ['role' => 'assistant', 'content' => 'answer']];

    $body = $this->postJson('/api/docs/ask', ['q' => 'how does rate limiting work', 'history' => $history])->streamedContent();

    $run = AiRun::where('agent', 'DocsAnswerAgent')->sole();
    expect($body)->toContain('"type":"sources"')
        ->and($body)->not->toContain('text_delta')
        ->and($run->status)->toBe('stopped')
        ->and($run->provider)->toBe(config('ai.default'))
        ->and($run->prompt_version)->toBe('docs-answer@v2')
        ->and($run->retrieved_chunk_ids)->toBe([$chunkId])
        ->and($run->history_turns)->toBe(2)
        ->and($run->origin)->toBe('api')
        ->and($run->tokens_out)->toBeNull()
        ->and($run->ttft_ms)->toBeNull()
        ->and($run->stopped_after_ms)->toBeNull();
});
