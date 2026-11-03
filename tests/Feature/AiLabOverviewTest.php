<?php

declare(strict_types=1);

use App\Models\AiRun;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function fakeAiRun(int $number): AiRun
{
    return AiRun::create([
        'invocation_id' => 'run-'.$number,
        'agent' => 'CarDescriptionAgent',
        'provider' => 'fake-stream',
        'model' => 'fake-slow-1',
        'prompt_version' => 'car-description@v1',
        'tokens_in' => 100 + $number,
        'tokens_out' => 20,
        'ttft_ms' => null,
        'latency_ms' => 500 + $number,
        'status' => $number % 2 === 0 ? 'failed' : 'completed',
        'created_at' => now()->subMinutes(60 - $number),
    ]);
}

it('AC-1 the overview shows models, Ollama status, capabilities and recent runs', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*/api/version' => Http::response(['version' => '0.12.0']),
    ]);

    foreach (range(1, 25) as $number) {
        fakeAiRun($number);
    }

    $this->get('/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Overview')
            ->where('textProvider', 'fake-stream')
            ->where('textModel', config('ai.providers.fake-stream.models.text.default'))
            ->where('embeddingProvider', 'ollama')
            ->where('embeddingModel', config('ai.providers.ollama.models.embeddings.default'))
            ->where('ollama.reachable', true)
            ->has('capabilities', 5)
            ->has('capabilities.0', fn (Assert $card) => $card
                ->where('key', 'describe')
                ->where('route', '/ai/describe')
                ->where('title', 'Describe')
                ->where('status', 'ready')
                ->where('slice', 'SL-22')
                ->etc())
            ->where('capabilities.3.status', 'coming')
            ->where('capabilities.3.slice', 'SL-45')
            ->where('capabilities.4.status', 'coming')
            ->where('capabilities.4.slice', 'SL-36')
            ->has('recentRuns', 20)
            ->has('recentRuns.0', fn (Assert $run) => $run
                ->where('invocation_id', 'run-25')
                ->where('agent', 'CarDescriptionAgent')
                ->where('provider', 'fake-stream')
                ->where('model', 'fake-slow-1')
                ->where('tokens_in', 125)
                ->where('tokens_out', 20)
                ->where('ttft_ms', null)
                ->where('latency_ms', 525)
                ->where('status', 'completed')
                ->has('created_at'))
            ->where('recentRuns.19.invocation_id', 'run-6'));
});

it('AC-1 the overview reports Ollama as unreachable when it does not answer', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*/api/version' => Http::response('down', 500),
    ]);

    $this->get('/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ollama.reachable', false)
            ->has('recentRuns', 0));
});

it('AC-1 the overview marks Ollama as not used when no provider uses it', function (): void {
    Http::preventStrayRequests();
    config()->set('ai.default_for_embeddings', 'fake-stream');

    $this->get('/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('ollama.reachable', 'not-used'));

    Http::assertNothingSent();
});
