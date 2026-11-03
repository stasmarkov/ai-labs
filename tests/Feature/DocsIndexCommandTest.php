<?php

declare(strict_types=1);

use App\Jobs\EmbedDocsBatchJob;
use App\Models\AiRun;
use App\Models\DocsChunk;
use App\Models\DocsDocument;
use App\Services\DocsEmbeddingService;
use App\Services\DocsIndexVersion;
use App\Services\MarkdownChunker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->root = storage_path('framework/testing/docs-index-'.uniqid());
    config([
        'ai.docs.repo.path' => $this->root.'/repo',
        'ai.docs.laravel.path' => $this->root.'/laravel',
        'ai.docs.embed_batch' => 2,
    ]);
    File::ensureDirectoryExists($this->root.'/repo');
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

function docsFile(string $relative, string $contents): void
{
    $path = test()->root.'/'.$relative;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
}

function docsMarkdown(string $title, int $sections = 1): string
{
    $markdown = "# {$title}\n";

    for ($section = 1; $section <= $sections; $section++) {
        $markdown .= "\n## Part {$section}\n\n".str_repeat("{$title} part {$section} explains one idea in a plain sentence. ", 40)."\n";
    }

    return $markdown;
}

function fakeDocsEmbeddings(array &$calls, int $size = 1024): void
{
    Embeddings::fake(function ($prompt) use (&$calls, $size): array {
        $calls[] = $prompt->inputs;

        return array_map(fn (): array => array_fill(0, $size, 0.5), $prompt->inputs);
    });
}

function seedRepoDocs(): void
{
    docsFile('repo/README.md', docsMarkdown('Readme', 2));
    docsFile('repo/BENCHMARKS.md', docsMarkdown('Benchmarks'));
    docsFile('repo/docs/guide.md', docsMarkdown('Guide', 2));
    docsFile('repo/docs/adr/0001-choice.md', docsMarkdown('Choice'));
}

function embeddedChunkCount(): int
{
    return DocsChunk::whereNotNull('embedding')->count();
}

it('AC-3 the first run stores documents and chunks and queues jobs in batches', function (): void {
    seedRepoDocs();
    Queue::fake();

    $this->artisan('docs:index')->assertSuccessful();

    $chunks = DocsChunk::count();
    $paths = DocsDocument::where('source', 'repo')->pluck('path')->all();
    sort($paths);
    expect($paths)
        ->toBe(['BENCHMARKS.md', 'README.md', 'docs/adr/0001-choice.md', 'docs/guide.md'])
        ->and($chunks)->toBeGreaterThan(4);

    Queue::assertPushed(EmbedDocsBatchJob::class, (int) ceil($chunks / 2));
    Queue::assertPushedOn('embeddings', EmbedDocsBatchJob::class);
    Queue::assertPushed(EmbedDocsBatchJob::class, fn (EmbedDocsBatchJob $job): bool => count($job->chunkIds) <= 2);

    $document = DocsDocument::where('path', 'README.md')->firstOrFail();
    expect($document->title)->toBe('Readme')
        ->and($document->content_hash)->toBe(hash('sha256', MarkdownChunker::VERSION.':500:300'."\n".File::get($this->root.'/repo/README.md')))
        ->and($document->indexed_at)->not->toBeNull();
});

it('AC-3 a second run with no change does nothing', function (): void {
    seedRepoDocs();
    $calls = [];
    fakeDocsEmbeddings($calls);

    $this->artisan('docs:index')->assertSuccessful();

    $documents = DB::table('docs_documents')->orderBy('id')->get()->all();
    $chunks = DB::table('docs_chunks')->orderBy('id')->get()->all();
    $callsAfterFirst = count($calls);
    expect(embeddedChunkCount())->toBe(count($chunks));

    Queue::fake();
    $this->artisan('docs:index')->expectsOutputToContain('Queued 0 jobs')->assertSuccessful();

    expect(DB::table('docs_documents')->orderBy('id')->get()->all())->toEqual($documents)
        ->and(DB::table('docs_chunks')->orderBy('id')->get()->all())->toEqual($chunks)
        ->and(count($calls))->toBe($callsAfterFirst);
    Queue::assertNothingPushed();
});

it('AC-3 changing one file re-chunks only it and keeps the vectors of unchanged chunks', function (): void {
    seedRepoDocs();
    $calls = [];
    fakeDocsEmbeddings($calls);
    $this->artisan('docs:index')->assertSuccessful();

    $untouched = DocsChunk::whereHas('document', fn ($query) => $query->where('path', '!=', 'docs/guide.md'))->orderBy('id')->pluck('id')->all();
    $sameSection = DocsChunk::whereHas('document', fn ($query) => $query->where('path', 'docs/guide.md'))->where('heading', 'Part 1')->firstOrFail();
    $callsBefore = count($calls);

    docsFile('repo/docs/guide.md', docsMarkdown('Guide', 1)."\n## Added\n\nA brand new section with its own text.\n");
    $this->artisan('docs:index')->assertSuccessful();

    $embedded = array_merge(...array_slice($calls, $callsBefore));
    expect(DocsChunk::whereIn('id', $untouched)->count())->toBe(count($untouched))
        ->and($embedded)->toHaveCount(1)
        ->and($embedded[0])->toContain('A brand new section')
        ->and(DocsChunk::whereNull('embedding')->count())->toBe(0);

    $kept = DocsChunk::whereHas('document', fn ($query) => $query->where('path', 'docs/guide.md'))->where('content_hash', $sameSection->content_hash)->firstOrFail();
    expect($kept->embedding_model)->toBe('ollama/bge-m3')
        ->and(DB::scalar('SELECT vector_dims(embedding) FROM docs_chunks WHERE id = ?', [$kept->id]))->toBe(1024)
        ->and(DocsDocument::where('path', 'docs/guide.md')->value('content_hash'))->toBe(hash('sha256', MarkdownChunker::VERSION.':500:300'."\n".File::get($this->root.'/repo/docs/guide.md')));
});

it('AC-3 deleting a file removes its document and chunks', function (): void {
    seedRepoDocs();
    $calls = [];
    fakeDocsEmbeddings($calls);
    $this->artisan('docs:index')->assertSuccessful();
    $removedId = DocsDocument::where('path', 'docs/guide.md')->value('id');

    File::delete($this->root.'/repo/docs/guide.md');
    $this->artisan('docs:index')->assertSuccessful();

    expect(DocsDocument::where('path', 'docs/guide.md')->exists())->toBeFalse()
        ->and(DocsChunk::where('document_id', $removedId)->exists())->toBeFalse()
        ->and(DocsDocument::count())->toBe(3);
});

it('AC-3 excluded paths are never read', function (): void {
    seedRepoDocs();
    $excluded = [
        'repo/CLAUDE.md', 'repo/AGENTS.md', 'repo/.claude/notes.md', 'repo/.ai/session/log.md', 'repo/.ai/reports/r.md',
        'repo/vendor/pkg/README.md', 'repo/node_modules/pkg/README.md', 'repo/docs/deep/nested.md',
    ];

    foreach ($excluded as $path) {
        docsFile($path, docsMarkdown('Secret'));
        chmod($this->root.'/'.$path, 0000);
    }

    docsFile('repo/.ai/specs/shipped/spec-lite.md', "---\nstatus: done\n---\n\n".docsMarkdown('Shipped'));
    docsFile('repo/.ai/specs/building/spec-lite.md', "---\nstatus: in-progress\n---\n\n".docsMarkdown('Building'));
    Queue::fake();

    $this->artisan('docs:index')->assertSuccessful();

    $paths = DocsDocument::pluck('path')->all();
    sort($paths);
    expect($paths)
        ->toBe(['.ai/specs/shipped/spec-lite.md', 'BENCHMARKS.md', 'README.md', 'docs/adr/0001-choice.md', 'docs/guide.md'])
        ->and(DocsDocument::where('title', 'Secret')->exists())->toBeFalse();
});

it('AC-3 indexes the Laravel docs without the readme, licence and table of contents', function (): void {
    seedRepoDocs();
    docsFile('laravel/queues.md', docsMarkdown('Queues'));
    docsFile('laravel/scheduling.md', docsMarkdown('Scheduling'));
    docsFile('laravel/readme.md', docsMarkdown('Readme'));
    docsFile('laravel/license.md', docsMarkdown('License'));
    docsFile('laravel/documentation.md', docsMarkdown('Documentation'));
    Queue::fake();

    $this->artisan('docs:index')->assertSuccessful();

    expect(DocsDocument::where('source', 'laravel')->orderBy('path')->pluck('path')->all())->toBe(['queues.md', 'scheduling.md']);
});

it('AC-3 a missing Laravel folder warns and keeps its documents', function (): void {
    seedRepoDocs();
    docsFile('laravel/queues.md', docsMarkdown('Queues'));
    $calls = [];
    fakeDocsEmbeddings($calls);
    $this->artisan('docs:index')->assertSuccessful();

    File::deleteDirectory($this->root.'/laravel');

    $this->artisan('docs:index')->expectsOutputToContain('docs:fetch-laravel')->assertSuccessful();
    expect(DocsDocument::where('source', 'laravel')->count())->toBe(1);

    $this->artisan('docs:index', ['--source' => 'laravel'])->expectsOutputToContain('docs:fetch-laravel')->assertFailed();
});

it('AC-3 refuses to queue while embedding jobs are still queued', function (): void {
    seedRepoDocs();
    $calls = [];
    fakeDocsEmbeddings($calls);
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert(['queue' => 'embeddings', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

    $this->artisan('docs:index')->expectsOutputToContain('still queued')->assertFailed();

    expect(DocsDocument::count())->toBe(0)->and($calls)->toBe([]);
});

it('AC-3 rejects an unknown source', function (): void {
    $this->artisan('docs:index', ['--source' => 'other'])->assertExitCode(2);
});

it('AC-4 a vector of the wrong length fails the batch and stores nothing for it', function (): void {
    seedRepoDocs();
    $calls = [];
    fakeDocsEmbeddings($calls, 768);
    $failures = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures): void {
        $failures[] = $event->exception->getMessage();
    });

    $this->artisan('docs:index')->assertSuccessful();

    expect($failures)->not->toBeEmpty()
        ->and($failures[0])->toContain('768')->toContain('1024')
        ->and(embeddedChunkCount())->toBe(0)
        ->and(AiRun::where('agent', 'embeddings:docs')->where('status', 'failed')->exists())->toBeTrue();
});

it('AC-4 chunk embeddings are logged as embeddings:docs', function (): void {
    seedRepoDocs();
    $calls = [];
    fakeDocsEmbeddings($calls);

    $this->artisan('docs:index')->assertSuccessful();

    $runs = AiRun::where('agent', 'embeddings:docs')->orderBy('id')->get();
    expect($runs)->toHaveCount(count($calls))
        ->and($runs->pluck('inputs')->all())->toBe(array_map('count', $calls))
        ->and($runs->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($runs->first()->provider)->toBe('ollama')
        ->and($runs->first()->model)->toBe('bge-m3')
        ->and($runs->first()->prompt_version)->toBeNull()
        ->and(AiRun::where('agent', 'embeddings')->count())->toBe(0);

    Embeddings::assertGenerated(fn ($prompt): bool => $prompt->provider->name() === 'ollama'
        && $prompt->model === 'bge-m3'
        && $prompt->dimensions === 1024);
});

it('AC-4 a change of the configured model re-embeds every chunk', function (): void {
    seedRepoDocs();
    $calls = [];
    fakeDocsEmbeddings($calls);
    $this->artisan('docs:index')->assertSuccessful();
    $total = DocsChunk::count();
    $callsBefore = count($calls);

    config(['ai.providers.ollama.models.embeddings.default' => 'other-embed']);
    $this->artisan('docs:index')->assertSuccessful();

    expect(array_sum(array_map('count', array_slice($calls, $callsBefore))))->toBe($total)
        ->and(DocsChunk::where('embedding_model', 'ollama/other-embed')->count())->toBe($total);
});

it('AC-7 the chunk settings are part of the document hash and recorded on the document', function (): void {
    docsFile('repo/README.md', docsMarkdown('Readme', 2));
    $calls = [];
    fakeDocsEmbeddings($calls);

    expect(config('ai.docs.chunk.max_tokens'))->toBe(500)->and(config('ai.docs.chunk.merge_below_tokens'))->toBe(300);
    $this->artisan('docs:index')->assertSuccessful();
    $document = DocsDocument::where('path', 'README.md')->firstOrFail();
    $firstHash = $document->content_hash;
    expect($document->chunk_max_tokens)->toBe(500)->and($document->chunk_merge_below_tokens)->toBe(300);

    config(['ai.docs.chunk.max_tokens' => 300, 'ai.docs.chunk.merge_below_tokens' => 200]);
    $this->artisan('docs:index')->assertSuccessful();

    $document->refresh();
    expect($document->content_hash)->not->toBe($firstHash)
        ->and($document->content_hash)->toBe(hash('sha256', MarkdownChunker::VERSION.':300:200'."\n".File::get($this->root.'/repo/README.md')))
        ->and($document->chunk_max_tokens)->toBe(300)
        ->and($document->chunk_merge_below_tokens)->toBe(200);
});

it('SL-40 docs:index still succeeds and reports the error when the cache store cannot forget the index version', function (): void {
    seedRepoDocs();
    Queue::fake();
    Cache::extend('failing', fn () => Cache::repository(new class extends ArrayStore
    {
        public function forget($key): bool
        {
            throw new RuntimeException('redis down');
        }
    }));
    config(['cache.stores.failing' => ['driver' => 'failing'], 'ai.docs.cache.store' => 'failing']);
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });

    $this->artisan('docs:index')->assertSuccessful();

    expect($reported)->toContain('redis down')
        ->and(DocsDocument::count())->toBeGreaterThan(0);
    Queue::assertPushed(EmbedDocsBatchJob::class);
});

it('SL-40 an embed batch forgets the memoized index version when it ends', function (): void {
    seedRepoDocs();
    config(['ai.docs.cache.store' => 'array']);
    Cache::store('array')->flush();
    $calls = [];
    fakeDocsEmbeddings($calls);
    Queue::fake();
    $this->artisan('docs:index')->assertSuccessful();
    $version = app(DocsIndexVersion::class);
    $pending = $version->current();
    expect($version->hasPendingEmbeddings())->toBeTrue();

    $ids = DocsChunk::query()->orderBy('id')->limit(2)->pluck('id')->all();
    (new EmbedDocsBatchJob($ids))->handle(app(DocsEmbeddingService::class), $version);

    expect(Cache::store('array')->has('docs-answer:index-version'))->toBeFalse()
        ->and($version->current())->not->toBe($pending);
});
