<?php

declare(strict_types=1);

use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\AiRunRecord;
use App\Http\Requests\DocsAskRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

it('AC-10 the chat page gets the ask endpoint, the run lookup and the question limit', function (): void {
    $this->get('/ai/chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Chat')
            ->where('askUrl', '/api/docs/ask')
            ->where('runUrl', '/ai/runs/RUN_ID')
            ->where('idPlaceholder', 'RUN_ID')
            ->where('maxQuestionLength', DocsAskRequest::MAX_QUESTION_LENGTH)
            ->missing('slices')
            ->missing('blockedBy'));
});

it('AC-10 the chat capability is ready and the overview lists photos as the only page coming', function (): void {
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);

    $this->get('/ai')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('capabilities.3.key', 'chat')
            ->where('capabilities.3.status', 'ready')
            ->where('capabilities.4.key', 'photos')
            ->where('capabilities.4.status', 'coming'));
});

it('MNC-3 the overview shows the refused and no_context runs as text', function (): void {
    foreach (['refused', 'no_context'] as $status) {
        app(AiRunRepositoryInterface::class)->save(new AiRunRecord(
            invocationId: "run-{$status}",
            agent: 'DocsAnswerAgent',
            provider: 'fake-stream',
            model: 'fake-slow-1',
            promptVersion: 'docs-answer.v1',
            tokensIn: null,
            tokensOut: null,
            ttftMs: null,
            latencyMs: 12,
            status: $status,
            errorMessage: null,
        ));
    }

    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);

    $statuses = collect($this->get('/ai')->assertOk()->inertiaProps('recentRuns'))->pluck('status')->sort()->values()->all();

    expect($statuses)->toBe(['no_context', 'refused']);
});

it('AC-10 the chat page links each known number in a comma-list citation marker', function (): void {
    $source = file_get_contents(resource_path('js/ai/Pages/Lab/Chat.vue'));

    expect($source)->toContain('\\[(\\d+(?:\\s*,\\s*\\d+)*)\\]');
});

it('AC-10 the chat page sends the use-cache checkbox as the cache field, on by default', function (): void {
    $source = file_get_contents(resource_path('js/ai/Pages/Lab/Chat.vue'));

    expect($source)
        ->toContain('id="chat-use-cache"')
        ->toContain('useCache = ref(true)')
        ->toContain('cache: useCache.value');
});

it('AC-10 the chat page shows the cached mark and looks the run up from cache_hit', function (): void {
    $source = file_get_contents(resource_path('js/ai/Pages/Lab/Chat.vue'));

    expect($source)
        ->toContain("event.type === 'cache_hit'")
        ->toContain('data-state="cache-hit"')
        ->toContain('cacheHit.value?.invocation_id')
        ->toContain('Matched an earlier question')
        ->not->toContain('cached_question')
        ->toContain('saved_latency_ms')
        ->toContain('similarity.toFixed(3)');
});

it('AC-10 the overview types and colours the cached run status', function (): void {
    $source = file_get_contents(resource_path('js/ai/Pages/Lab/Overview.vue'));

    expect($source)->toContain("'cached'");
});

it('AC-10 the overview lists a cached run as text', function (): void {
    app(AiRunRepositoryInterface::class)->save(new AiRunRecord(
        invocationId: 'run-cached',
        agent: 'DocsAnswerAgent',
        provider: 'fake-stream',
        model: 'fake-slow-1',
        promptVersion: 'docs-answer.v1',
        tokensIn: null,
        tokensOut: null,
        ttftMs: null,
        latencyMs: 12,
        status: 'cached',
        errorMessage: null,
    ));

    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);

    $this->get('/ai')->assertOk()->assertInertia(fn (Assert $page) => $page->where('recentRuns.0.status', 'cached'));
});
