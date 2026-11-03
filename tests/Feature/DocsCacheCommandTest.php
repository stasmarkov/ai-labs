<?php

declare(strict_types=1);

use App\Ai\DocsAnswerAgent;
use App\Models\AiRun;
use App\Models\DocsDocument;
use App\Services\DocsAnswerCache;
use App\Services\DocsEmbeddingService;
use App\Services\DocsIndexVersion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

function cmdRun(string $status, ?string $kind = null, ?int $saved = null, string $agent = 'DocsAnswerAgent', ?string $createdAt = null, ?string $origin = 'api'): void
{
    $run = AiRun::create([
        'invocation_id' => (string) str()->uuid(),
        'agent' => $agent,
        'provider' => 'ollama',
        'model' => 'm',
        'latency_ms' => 10,
        'status' => $status,
        'cache_kind' => $kind,
        'saved_latency_ms' => $saved,
        'origin' => $origin,
    ]);

    if ($createdAt !== null) {
        DB::table('ai_runs')->where('id', $run->id)->update(['created_at' => $createdAt]);
    }
}

function cmdEntry(string $question, array $overrides = []): int
{
    $agent = new DocsAnswerAgent;
    $vector = array_fill(0, 1024, 0.0);
    $vector[0] = 1.0;

    return DB::table('docs_answer_cache')->insertGetId(array_merge([
        'question' => $question,
        'question_hash' => hash('sha256', DocsAnswerCache::normalize($question)),
        'embedding' => json_encode($vector),
        'answer' => 'a [1]',
        'sources' => '[]',
        'cited_chunk_ids' => '[1]',
        'retrieved_chunk_ids' => '[1]',
        'provider' => 'ollama',
        'prompt_version' => $agent->promptVersion(),
        'answer_model' => $agent->modelName(),
        'embedding_model' => app(DocsEmbeddingService::class)->modelLabel(),
        'index_version' => app(DocsIndexVersion::class)->current(),
        'source_invocation_id' => 'inv',
        'source_latency_ms' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

beforeEach(function (): void {
    config(['ai.docs.cache.store' => 'array', 'ai.docs.cache.ttl_hours' => 168]);
    Cache::store('array')->flush();
    DocsDocument::create(['source' => 'repo', 'path' => 'a.md', 'title' => 'a', 'content_hash' => str_repeat('a', 64)]);
});

it('AC-6 requires --stats or --prune', function (): void {
    $this->artisan('docs:cache')->expectsOutputToContain('Pass --stats')->assertExitCode(2);
});

it('AC-6 stats print hit rate, hits by kind, saved latency and current vs stale entries per window', function (): void {
    cmdRun('cached', 'exact', 1000);
    cmdRun('cached', 'semantic', 3000);
    cmdRun('cached', 'semantic', 2000);
    cmdRun('completed');
    cmdRun('completed', createdAt: now()->subDays(3)->toDateTimeString());
    cmdRun('cached', 'exact', 4000, createdAt: now()->subDays(3)->toDateTimeString());
    cmdRun('refused');
    cmdRun('completed', agent: 'CarDescriptionAgent');
    cmdEntry('current one');
    cmdEntry('current two');
    cmdEntry('old prompt', ['prompt_version' => 'docs-answer@v0']);
    cmdEntry('expired', ['created_at' => now()->subDays(30)]);

    expect(Artisan::call('docs:cache', ['--stats' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('75.0%', 'exact: 1, semantic: 2', '66.7%', 'exact: 2, semantic: 2', 'Entries: 2 current, 2 stale.');
});

it('AC-6 stats ignore eval rows and rows without an origin when computing the hit rate', function (): void {
    cmdRun('cached', 'exact', 1000);
    cmdRun('completed');
    cmdRun('completed', origin: 'eval');
    cmdRun('completed', origin: 'eval');
    cmdRun('completed', origin: null);

    Artisan::call('docs:cache', ['--stats' => true]);
    $day = collect(explode("\n", Artisan::output()))->first(fn (string $line): bool => str_contains($line, 'Last 24 h'));

    expect($day)->toContain('50.0%');
});

it('AC-6 stats show n/a on an empty history', function (): void {
    expect(Artisan::call('docs:cache', ['--stats' => true]))->toBe(0);

    expect(Artisan::output())->toContain('n/a', 'Entries: 0 current, 0 stale.');
});

it('AC-6 saved latency sum and median are over the cached runs of the window', function (): void {
    cmdRun('cached', 'exact', 1000);
    cmdRun('cached', 'exact', 2000);
    cmdRun('cached', 'exact', 6000);
    cmdRun('cached', 'exact', 9000, createdAt: now()->subDays(2)->toDateTimeString());

    Artisan::call('docs:cache', ['--stats' => true]);
    $lines = explode('
', Artisan::output());
    $day = collect($lines)->first(fn (string $line): bool => str_contains($line, 'Last 24 h'));
    $all = collect($lines)->first(fn (string $line): bool => str_contains($line, 'All time'));

    expect($day)->toContain('9000')->toContain('2000')
        ->and($all)->toContain('18000')->toContain('4000');
});

it('AC-6 prune deletes stale and expired entries with their exact keys and keeps current ones', function (): void {
    $keep = cmdEntry('keep me');
    $stale = cmdEntry('stale one', ['index_version' => str_repeat('0', 64)]);
    $expired = cmdEntry('expired one', ['created_at' => now()->subDays(30)]);

    $store = Cache::store('array');
    $keys = [];
    foreach (['keep me' => $keep, 'stale one' => $stale, 'expired one' => $expired] as $question => $id) {
        $row = DB::table('docs_answer_cache')->find($id);
        $key = 'docs-answer:exact:'.hash('sha256', implode("\n", [
            DocsAnswerCache::normalize($question), $row->prompt_version, $row->answer_model, $row->embedding_model, $row->index_version,
        ]));
        $store->put($key, $id, 3600);
        $keys[$question] = $key;
    }

    $this->artisan('docs:cache --prune')->expectsOutputToContain('Pruned 2 entries.')->assertSuccessful();

    expect(DB::table('docs_answer_cache')->pluck('id')->all())->toBe([$keep])
        ->and($store->has($keys['keep me']))->toBeTrue()
        ->and($store->has($keys['stale one']))->toBeFalse()
        ->and($store->has($keys['expired one']))->toBeFalse();
});

it('AC-6 prune with nothing to delete reports zero', function (): void {
    cmdEntry('keep me');

    $this->artisan('docs:cache --prune')->expectsOutputToContain('Pruned 0 entries.')->assertSuccessful();

    expect(DB::table('docs_answer_cache')->count())->toBe(1);
});
