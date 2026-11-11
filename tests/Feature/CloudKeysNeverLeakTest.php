<?php

declare(strict_types=1);

use App\Ai\AiRunRecorder;
use App\Ai\ListingFilterAgent;
use App\Models\AiRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const LEAK_KEYS = ['sk-ant-leak-canary-1111', 'sk-openai-leak-canary-2222', 'gem-leak-canary-3333'];

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->logFile = storage_path('logs/cloud-keys-leak-'.uniqid().'.log');
    config([
        'logging.default' => 'leakcheck',
        'logging.channels.leakcheck' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
        'ai.default' => 'fake-stream',
        'ai.cloud_per_minute' => 100,
        'ai.nl_search.per_minute' => 100,
        'ai.providers.anthropic.key' => LEAK_KEYS[0],
        'ai.providers.openai.key' => LEAK_KEYS[1],
        'ai.providers.gemini.key' => LEAK_KEYS[2],
    ]);
    Log::forgetChannel('leakcheck');
});

afterEach(function (): void {
    @unlink($this->logFile);
});

function assertNoKey(string $haystack, string $where): void
{
    foreach (LEAK_KEYS as $key) {
        expect(str_contains($haystack, $key))->toBeFalse("a key value appears in {$where}");
    }
}

it('NFR-1 no key value appears in any lab page, the guides, the log or ai_runs', function (): void {
    ListingFilterAgent::fake([['make' => null, 'model' => null, 'city' => null, 'fuel' => null, 'min_year' => null, 'max_price_eur' => null, 'unsupported' => []], ['make' => null, 'model' => null, 'city' => null, 'fuel' => null, 'min_year' => null, 'max_price_eur' => null, 'unsupported' => []]]);

    $this->getJson('/api/listings/nl?q=a&provider=anthropic')->assertOk();
    $this->getJson('/api/listings/nl?q=b&provider=gemini')->assertStatus(200);

    foreach (['/', '/ai', '/ai/describe', '/ai/semantic', '/ai/docs', '/ai/chat', '/ai/nl-search', '/ai/photos', '/ai/photo-search', '/ai/guides'] as $url) {
        $page = $this->get($url)->assertOk();
        assertNoKey($page->getContent(), $url);
    }

    foreach (['providers', 'describe', 'nl-search', 'docs', 'chat', 'semantic'] as $guide) {
        $page = $this->get('/ai/guides/'.$guide)->assertOk();
        assertNoKey($page->getContent(), "guide {$guide}");
    }

    foreach (AiRun::all() as $run) {
        assertNoKey($run->toJson(), 'ai_runs');
        assertNoKey($this->get('/ai/runs/'.$run->invocation_id)->getContent(), 'run page');
    }

    assertNoKey(is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '', 'the log');
});

it('NFR-1 and MNC-4 a rejected or key-less cloud request leaks no key and calls no network', function (): void {
    config(['ai.providers.openai.key' => null]);

    $rejected = $this->getJson('/api/listings/nl?q=a&provider=openai');

    $rejected->assertStatus(422);
    assertNoKey($rejected->getContent(), 'the 422 body');
    expect(AiRun::count())->toBe(0);
    Http::assertNothingSent();
});

it('NFR-1 a cloud error that echoes the key is redacted before it is stored and shown on the run page', function (): void {
    $error = new RuntimeException('Incorrect API key provided: '.LEAK_KEYS[1].' for org-abcdef123456; also AIzaSyLeakCanary12345');

    $id = app(AiRunRecorder::class)->recordRun(
        status: 'failed',
        agent: 'CarDescriptionAgent',
        provider: 'openai',
        model: 'some-model',
        promptVersion: null,
        latencyMs: 5,
        retrieved: null,
        exception: $error,
    );

    $stored = (string) AiRun::query()->where('invocation_id', $id)->value('error_message');

    expect($stored)->toContain('[redacted]')->not->toContain('org-abcdef123456')->not->toContain('AIzaSyLeakCanary12345');
    assertNoKey($stored, 'the stored error message');
    assertNoKey((string) $this->get('/ai/runs/'.$id)->getContent(), 'the run page');
});
