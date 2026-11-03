<?php

declare(strict_types=1);

use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\AiRunRecord;
use App\Models\AiRun;
use App\Models\DocsDocument;
use App\Services\DocsIndexVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Embeddings;

function versionDocument(string $path, string $hash, string $source = 'repo'): void
{
    DocsDocument::create(['source' => $source, 'path' => $path, 'title' => $path, 'content_hash' => $hash]);
}

it('AC-4 the index version changes when a document hash changes and stays when nothing changes', function (): void {
    versionDocument('a.md', str_repeat('a', 64));
    versionDocument('b.md', str_repeat('b', 64));
    $version = app(DocsIndexVersion::class);

    $first = $version->current();
    expect($first)->toMatch('/^[0-9a-f]{64}$/');

    $version->forget();
    expect($version->current())->toBe($first);

    DocsDocument::where('path', 'b.md')->update(['content_hash' => str_repeat('c', 64)]);
    $version->forget();
    expect($version->current())->not->toBe($first);
});

it('AC-4 the index version follows added and removed documents and the embedding model', function (): void {
    versionDocument('a.md', str_repeat('a', 64));
    $version = app(DocsIndexVersion::class);
    $one = $version->current();

    versionDocument('z.md', str_repeat('z', 64), 'laravel');
    $version->forget();
    $two = $version->current();
    expect($two)->not->toBe($one);

    DocsDocument::where('path', 'z.md')->delete();
    $version->forget();
    expect($version->current())->toBe($one);

    config(['ai.providers.ollama.models.embeddings.default' => 'other-model']);
    $version->forget();
    expect($version->current())->not->toBe($one);
});

it('AC-4 the index version is hashed over ordered source, path and hash lines then the embedding model', function (): void {
    versionDocument('b.md', str_repeat('b', 64), 'repo');
    versionDocument('a.md', str_repeat('a', 64), 'repo');
    versionDocument('c.md', str_repeat('c', 64), 'laravel');

    $expected = hash('sha256', 'laravel|c.md|'.str_repeat('c', 64)."\n".'repo|a.md|'.str_repeat('a', 64)."\n".'repo|b.md|'.str_repeat('b', 64)."\n".'ollama/bge-m3');

    expect(app(DocsIndexVersion::class)->current())->toBe($expected);
});

it('AC-4 the index version is memoized for 60 seconds under docs-answer:index-version', function (): void {
    versionDocument('a.md', str_repeat('a', 64));
    $version = app(DocsIndexVersion::class);
    $first = $version->current();

    DocsDocument::where('path', 'a.md')->update(['content_hash' => str_repeat('d', 64)]);
    DB::enableQueryLog();

    expect($version->current())->toBe($first)
        ->and(DB::getQueryLog())->toBe([])
        ->and(Cache::get('docs-answer:index-version'))->toBe($first);
});

it('AC-4 docs:index forgets the memoized version when it changes a document', function (): void {
    $root = storage_path('framework/testing/docs-version-'.uniqid());
    File::ensureDirectoryExists($root.'/repo');
    config(['ai.docs.repo.path' => $root.'/repo', 'ai.docs.laravel.path' => $root.'/laravel']);
    Embeddings::fake();
    File::put($root.'/repo/README.md', "# One\n\nFirst text.\n");

    $version = app(DocsIndexVersion::class);
    $this->artisan('docs:index')->assertSuccessful();
    $first = $version->current();

    $this->artisan('docs:index')->assertSuccessful();
    expect($version->current())->toBe($first);

    File::put($root.'/repo/README.md', "# One\n\nChanged text.\n");
    $this->artisan('docs:index')->assertSuccessful();
    expect($version->current())->not->toBe($first);

    File::delete($root.'/repo/README.md');
    $this->artisan('docs:index')->assertSuccessful();
    expect($version->current())->not->toBe($first);

    File::deleteDirectory($root);
});

it('AC-5 ai_runs carries the cache columns and the cached status', function (): void {
    AiRun::create([
        'invocation_id' => 'cached-1', 'agent' => 'DocsAnswerAgent', 'provider' => 'ollama', 'model' => 'm',
        'prompt_version' => 'docs-answer@v1', 'latency_ms' => 5, 'status' => 'cached',
        'cache_kind' => 'exact', 'cache_similarity' => 1.0, 'cache_entry_id' => 7, 'saved_latency_ms' => 8000, 'origin' => 'api',
    ]);

    $run = AiRun::where('invocation_id', 'cached-1')->firstOrFail();
    expect($run->cache_kind)->toBe('exact')
        ->and($run->cache_similarity)->toBe(1.0)
        ->and($run->cache_entry_id)->toBe(7)
        ->and($run->saved_latency_ms)->toBe(8000)
        ->and($run->origin)->toBe('api');
});

it('AC-5 the repository saves the cache fields of a run record', function (): void {
    app(AiRunRepositoryInterface::class)->save(new AiRunRecord(
        invocationId: 'cached-2', agent: 'DocsAnswerAgent', provider: 'ollama', model: 'm', promptVersion: 'docs-answer@v1',
        tokensIn: null, tokensOut: null, ttftMs: null, latencyMs: 4, status: 'cached',
        retrievedChunkIds: [1, 2], citedChunkIds: [1],
        cacheKind: 'semantic', cacheSimilarity: 0.912, cacheEntryId: 3, savedLatencyMs: 8840, origin: 'api',
    ));

    $run = AiRun::where('invocation_id', 'cached-2')->firstOrFail();
    expect($run->cache_kind)->toBe('semantic')
        ->and($run->cache_similarity)->toBe(0.912)
        ->and($run->cache_entry_id)->toBe(3)
        ->and($run->saved_latency_ms)->toBe(8840)
        ->and($run->origin)->toBe('api')
        ->and($run->cited_chunk_ids)->toBe([1]);
});

it('AC-4 the answer cache table has the unique version key and the indexes', function (): void {
    $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE tablename = 'docs_answer_cache'"))->pluck('indexdef', 'indexname');

    expect($indexes->contains(fn (string $def): bool => str_contains($def, 'hnsw') && str_contains($def, 'vector_cosine_ops')))->toBeTrue()
        ->and($indexes->contains(fn (string $def): bool => str_contains($def, 'UNIQUE') && str_contains($def, 'question_hash') && str_contains($def, 'index_version')))->toBeTrue();
});

it('the phpunit environment keeps the answer cache off', function (): void {
    expect(config('ai.docs.cache.enabled'))->toBeFalse();
});
