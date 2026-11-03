<?php

declare(strict_types=1);

use App\Contracts\DocsAnswerCacheRepositoryInterface;
use App\DTOs\DocsCacheCandidate;
use App\DTOs\DocsCacheEntry;
use App\DTOs\DocsCacheNewEntry;
use App\DTOs\DocsCacheVersions;
use App\Enums\DocsAnswerOutcome;
use App\Enums\DocsCacheKind;
use App\Models\DocsDocument;
use App\Services\DocsAnswerCache;
use App\Services\DocsIndexVersion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

const CACHE_PROMPT = 'docs-answer@v1';
const CACHE_MODEL = 'qwen3.5:9b';

/**
 * @return list<float>
 */
function cacheVector(float $x, float $y = 0.0): array
{
    $vector = array_fill(0, 1024, 0.0);
    $vector[0] = $x;
    $vector[1] = $y;

    return $vector;
}

/**
 * A unit vector whose cosine to cacheVector(1) is $similarity.
 *
 * @return list<float>
 */
function cacheVectorAt(float $similarity): array
{
    return cacheVector($similarity, sqrt(1 - $similarity ** 2));
}

function cacheCandidate(string $question = 'How do I validate a request?', ?array $vector = null, array $overrides = []): DocsCacheCandidate
{
    $versions = cacheService()->versions($overrides['promptVersion'] ?? CACHE_PROMPT, $overrides['answerModel'] ?? CACHE_MODEL);
    unset($overrides['promptVersion'], $overrides['answerModel']);

    return new DocsCacheCandidate(...array_merge([
        'question' => $question,
        'vector' => $vector ?? cacheVector(1),
        'outcome' => DocsAnswerOutcome::Answered,
        'answer' => 'Use a form request [1].',
        'sources' => [['n' => 1, 'id' => 7, 'path' => 'validation.md']],
        'citedChunkIds' => [7],
        'retrievedChunkIds' => [7, 8],
        'provider' => 'ollama',
        'versions' => $versions,
        'sourceInvocationId' => 'inv-1',
        'sourceLatencyMs' => 9000,
    ], $overrides));
}

function cacheService(): DocsAnswerCache
{
    return app(DocsAnswerCache::class);
}

beforeEach(function (): void {
    config(['ai.docs.cache.store' => 'array', 'ai.docs.cache.min_similarity' => 0.85, 'ai.docs.cache.ttl_hours' => 168]);
    Cache::store('array')->flush();
    DocsDocument::create(['source' => 'repo', 'path' => 'a.md', 'title' => 'a', 'content_hash' => str_repeat('a', 64)]);
});

it('AC-1 normalization lowercases, trims, collapses whitespace and drops trailing question marks, bangs and dots', function (string $raw, string $expected): void {
    expect(DocsAnswerCache::normalize($raw))->toBe($expected);
})->with([
    'case' => ['How Do I VALIDATE', 'how do i validate'],
    'trim' => ["  how do i validate \n", 'how do i validate'],
    'whitespace runs' => ["how\t do   i\nvalidate", 'how do i validate'],
    'trailing marks' => ['how do i validate?!.?', 'how do i validate'],
    'marks then space' => ['how do i validate ? ', 'how do i validate'],
    'inner marks kept' => ['what is v1.2? really', 'what is v1.2? really'],
    'multibyte' => ['ÜBER Äpfel?', 'über äpfel'],
]);

it('AC-1 an exact hit serves a stored answer for a differently written question', function (): void {
    expect(cacheService()->store(cacheCandidate('How do I validate a request?')))->toBeTrue();

    $hit = cacheService()->lookupExact('  how do i  VALIDATE a request ?! ', CACHE_PROMPT, CACHE_MODEL);

    expect($hit)->not->toBeNull()
        ->and($hit->kind)->toBe(DocsCacheKind::Exact)
        ->and($hit->similarity)->toBe(1.0)
        ->and($hit->entry->answer)->toBe('Use a form request [1].')
        ->and($hit->entry->question)->toBe('How do I validate a request?')
        ->and($hit->entry->citedChunkIds)->toBe([7])
        ->and($hit->entry->retrievedChunkIds)->toBe([7, 8])
        ->and($hit->entry->sources)->toBe([['n' => 1, 'id' => 7, 'path' => 'validation.md']])
        ->and($hit->entry->sourceLatencyMs)->toBe(9000)
        ->and($hit->entry->sourceInvocationId)->toBe('inv-1');
});

it('AC-1 a different question is an exact miss', function (): void {
    cacheService()->store(cacheCandidate());

    expect(cacheService()->lookupExact('How do I validate a form?', CACHE_PROMPT, CACHE_MODEL))->toBeNull();
});

it('AC-1 the exact key lives for the configured ttl', function (): void {
    config(['ai.docs.cache.ttl_hours' => 2]);
    cacheService()->store(cacheCandidate());

    $this->travel(119)->minutes();
    expect(cacheService()->lookupExact('How do I validate a request?', CACHE_PROMPT, CACHE_MODEL))->not->toBeNull();

    $this->travel(2)->minutes();
    expect(cacheService()->lookupExact('How do I validate a request?', CACHE_PROMPT, CACHE_MODEL))->toBeNull();
});

it('AC-1 a dangling exact key is a miss and is deleted', function (): void {
    cacheService()->store(cacheCandidate());
    $key = collect(array_keys((fn () => $this->storage)->call(Cache::store('array')->getStore())))->first(fn (string $k): bool => str_contains($k, 'docs-answer:exact:'));
    DB::table('docs_answer_cache')->delete();

    expect(cacheService()->lookupExact('How do I validate a request?', CACHE_PROMPT, CACHE_MODEL))->toBeNull()
        ->and(Cache::store('array')->has($key))->toBeFalse();
});

it('AC-4 an exact entry is a miss for each of the four versions', function (string $version): void {
    cacheService()->store(cacheCandidate());
    $question = 'How do I validate a request?';

    match ($version) {
        'prompt' => expect(cacheService()->lookupExact($question, 'docs-answer@v2', CACHE_MODEL))->toBeNull(),
        'answer model' => expect(cacheService()->lookupExact($question, CACHE_PROMPT, 'other:7b'))->toBeNull(),
        'embedding model' => (function () use ($question): void {
            config(['ai.providers.ollama.models.embeddings.default' => 'other-embed']);
            app(DocsIndexVersion::class)->forget();
            expect(cacheService()->lookupExact($question, CACHE_PROMPT, CACHE_MODEL))->toBeNull();
        })(),
        'index' => (function () use ($question): void {
            DocsDocument::query()->update(['content_hash' => str_repeat('b', 64)]);
            app(DocsIndexVersion::class)->forget();
            expect(cacheService()->lookupExact($question, CACHE_PROMPT, CACHE_MODEL))->toBeNull();
        })(),
    };
})->with(['prompt', 'answer model', 'embedding model', 'index']);

it('AC-4 the exact key is hashed over the normalized question and the four versions', function (): void {
    cacheService()->store(cacheCandidate());
    $index = cacheService()->indexVersion();
    $expected = 'docs-answer:exact:'.hash('sha256', implode("\n", ['how do i validate a request', CACHE_PROMPT, CACHE_MODEL, 'ollama/bge-m3', $index]));

    expect(Cache::store('array')->get($expected))->toBeInt();
});

it('AC-4 a semantic entry is a miss for each of the four versions', function (string $version): void {
    cacheService()->store(cacheCandidate());
    $prompt = CACHE_PROMPT;
    $model = CACHE_MODEL;

    match ($version) {
        'prompt' => $prompt = 'docs-answer@v2',
        'answer model' => $model = 'other:7b',
        'embedding model' => config(['ai.providers.ollama.models.embeddings.default' => 'other-embed']),
        'index' => DocsDocument::query()->update(['content_hash' => str_repeat('b', 64)]),
    };
    app(DocsIndexVersion::class)->forget();

    expect(cacheService()->lookupSemantic(cacheVector(1), $prompt, $model))->toBeNull();
})->with(['prompt', 'answer model', 'embedding model', 'index']);

it('AC-2 a semantic lookup returns the nearest current entry with its similarity', function (): void {
    cacheService()->store(cacheCandidate('first question', cacheVector(1)));
    cacheService()->store(cacheCandidate('second question', cacheVectorAt(0.5), ['answer' => 'Other [1].']));

    $hit = cacheService()->lookupSemantic(cacheVectorAt(0.95), CACHE_PROMPT, CACHE_MODEL);

    expect($hit->kind)->toBe(DocsCacheKind::Semantic)
        ->and($hit->entry->question)->toBe('first question')
        ->and($hit->similarity)->toEqualWithDelta(0.95, 1e-6);
});

it('AC-2 the nearest current entry wins over a nearer stale one', function (): void {
    cacheService()->store(cacheCandidate('stale', cacheVector(1), ['promptVersion' => 'docs-answer@v0']));
    cacheService()->store(cacheCandidate('current', cacheVectorAt(0.9)));

    expect(cacheService()->lookupSemantic(cacheVector(1), CACHE_PROMPT, CACHE_MODEL)->entry->question)->toBe('current');
});

it('AC-2 similarity equal to the threshold is a hit and below it is a miss', function (): void {
    cacheService()->store(cacheCandidate('stored', cacheVector(1)));
    $query = cacheVector(1);
    $exact = cacheService()->lookupSemantic($query, CACHE_PROMPT, CACHE_MODEL)->similarity;

    config(['ai.docs.cache.min_similarity' => $exact]);
    expect(cacheService()->lookupSemantic($query, CACHE_PROMPT, CACHE_MODEL))->not->toBeNull();

    config(['ai.docs.cache.min_similarity' => $exact + 1e-9]);
    expect(cacheService()->lookupSemantic($query, CACHE_PROMPT, CACHE_MODEL))->toBeNull();
});

it('AC-2 a vector below the threshold misses', function (): void {
    cacheService()->store(cacheCandidate('stored', cacheVector(1)));

    expect(cacheService()->lookupSemantic(cacheVectorAt(0.80), CACHE_PROMPT, CACHE_MODEL))->toBeNull()
        ->and(cacheService()->lookupSemantic(cacheVectorAt(0.86), CACHE_PROMPT, CACHE_MODEL))->not->toBeNull();
});

it('AC-2 an expired entry is ignored by both lookups', function (): void {
    cacheService()->store(cacheCandidate());
    DB::table('docs_answer_cache')->update(['created_at' => now()->subHours(169)]);

    expect(cacheService()->lookupSemantic(cacheVector(1), CACHE_PROMPT, CACHE_MODEL))->toBeNull()
        ->and(cacheService()->lookupExact('How do I validate a request?', CACHE_PROMPT, CACHE_MODEL))->toBeNull();
});

it('AC-3 store writes the row and the exact key', function (): void {
    expect(cacheService()->store(cacheCandidate()))->toBeTrue()
        ->and(DB::table('docs_answer_cache')->count())->toBe(1);

    $row = DB::table('docs_answer_cache')->first();
    expect($row->question_hash)->toBe(hash('sha256', 'how do i validate a request'))
        ->and($row->prompt_version)->toBe(CACHE_PROMPT)
        ->and($row->answer_model)->toBe(CACHE_MODEL)
        ->and($row->embedding_model)->toBe('ollama/bge-m3')
        ->and($row->index_version)->toBe(cacheService()->indexVersion())
        ->and($row->provider)->toBe('ollama')
        ->and($row->hit_count)->toBe(0);
});

it('AC-3 store refuses every outcome other than a completed answer', function (DocsAnswerOutcome $outcome): void {
    expect(cacheService()->store(cacheCandidate(overrides: ['outcome' => $outcome])))->toBeFalse()
        ->and(DB::table('docs_answer_cache')->count())->toBe(0)
        ->and(cacheService()->lookupExact('How do I validate a request?', CACHE_PROMPT, CACHE_MODEL))->toBeNull();
})->with([
    DocsAnswerOutcome::RefusedInput,
    DocsAnswerOutcome::RefusedOutput,
    DocsAnswerOutcome::NoContext,
    DocsAnswerOutcome::Failed,
]);

it('AC-3 store refuses an uncited, aborted or empty answer', function (array $overrides): void {
    expect(cacheService()->store(cacheCandidate(overrides: $overrides)))->toBeFalse()
        ->and(DB::table('docs_answer_cache')->count())->toBe(0);
})->with([
    'uncited' => [['citedChunkIds' => []]],
    'aborted' => [['aborted' => true]],
    'empty answer' => [['answer' => "  \n"]],
]);

it('AC-3 storing the same question again replaces the answer, keeps the hit count and the same id', function (): void {
    cacheService()->store(cacheCandidate());
    DB::table('docs_answer_cache')->update(['hit_count' => 4]);
    $id = DB::table('docs_answer_cache')->value('id');

    cacheService()->store(cacheCandidate('  HOW do I validate a request??', overrides: ['answer' => 'New [1].', 'sourceInvocationId' => 'inv-2', 'sourceLatencyMs' => 5000]));

    $row = DB::table('docs_answer_cache')->first();
    expect(DB::table('docs_answer_cache')->count())->toBe(1)
        ->and($row->id)->toBe($id)
        ->and($row->answer)->toBe('New [1].')
        ->and($row->source_invocation_id)->toBe('inv-2')
        ->and($row->source_latency_ms)->toBe(5000)
        ->and($row->hit_count)->toBe(4)
        ->and(cacheService()->lookupExact('how do i validate a request', CACHE_PROMPT, CACHE_MODEL)->entry->id)->toBe($id);
});

it('AC-3 a failing upsert is reported, not thrown, and writes no exact key', function (): void {
    $this->app->instance(DocsAnswerCacheRepositoryInterface::class, new class implements DocsAnswerCacheRepositoryInterface
    {
        public function findCurrent(int $id, DocsCacheVersions $versions, CarbonImmutable $notBefore): ?DocsCacheEntry
        {
            return null;
        }

        public function nearestCurrent(array $vector, DocsCacheVersions $versions, CarbonImmutable $notBefore, int $efSearch): ?array
        {
            return null;
        }

        public function upsert(DocsCacheNewEntry $entry): int
        {
            throw new RuntimeException('db down');
        }

        public function recordHit(int $id): void {}

        public function countByCurrency(DocsCacheVersions $versions, CarbonImmutable $notBefore): array
        {
            return ['current' => 0, 'stale' => 0];
        }

        public function deleteNotCurrent(DocsCacheVersions $versions, CarbonImmutable $notBefore): array
        {
            return [];
        }
    });
    $reported = [];
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported): void {
        $reported[] = $e->getMessage();
    });

    expect(cacheService()->store(cacheCandidate()))->toBeFalse()
        ->and($reported)->toBe(['db down']);
});

it('AC-3 a failing exact key write is reported and the entry stays reachable semantically', function (): void {
    config(['ai.docs.cache.store' => 'broken']);
    config(['cache.stores.broken' => ['driver' => 'array']]);
    $broken = Mockery::mock(Repository::class);
    $broken->shouldReceive('remember')->andReturn(cacheService()->indexVersion());
    $broken->shouldReceive('put')->andThrow(new RuntimeException('redis down'));
    Cache::shouldReceive('store')->with('broken')->andReturn($broken);

    expect(cacheService()->store(cacheCandidate()))->toBeTrue()
        ->and(DB::table('docs_answer_cache')->count())->toBe(1);
});

function cachePendingChunk(): int
{
    return DocsDocument::query()->firstOrFail()->chunks()->create([
        'position' => 0, 'heading' => 'H', 'body' => 'b', 'content' => 'a.md › H', 'token_estimate' => 3, 'content_hash' => hash('sha256', 'b'),
    ])->id;
}

it('AC-1 normalization removes every trailing run of whitespace, question marks, bangs and dots', function (string $raw, string $expected): void {
    expect(DocsAnswerCache::normalize($raw))->toBe($expected);
})->with([
    'spaced question marks' => ['How do I retry? ?', 'how do i retry'],
    'spaced dots' => ['How do I retry. . .', 'how do i retry'],
    'mixed' => ["How do I retry ?!.  \n", 'how do i retry'],
    'inner punctuation stays' => ['What is a.b? Really?', 'what is a.b? really'],
    'only punctuation' => ['?! .', ''],
]);

it('AC-4 store files the entry under the versions it was planned with, not the index version at store time', function (): void {
    $candidate = cacheCandidate();
    $planned = $candidate->versions->indexVersion;

    DocsDocument::query()->update(['content_hash' => str_repeat('b', 64)]);
    app(DocsIndexVersion::class)->forget();
    expect(cacheService()->indexVersion())->not->toBe($planned);

    expect(cacheService()->store($candidate))->toBeTrue()
        ->and(DB::table('docs_answer_cache')->sole()->index_version)->toBe($planned)
        ->and(cacheService()->lookupExact('How do I validate a request?', CACHE_PROMPT, CACHE_MODEL))->toBeNull();
});

it('AC-4 the index version includes the chunks that still lack a vector, and returns to the plain hash once all have one', function (): void {
    $version = app(DocsIndexVersion::class);
    $complete = $version->current();
    $chunkId = cachePendingChunk();
    $version->forget();
    $partial = $version->current();

    expect($partial)->not->toBe($complete)
        ->and($version->hasPendingEmbeddings())->toBeTrue();

    DB::update('UPDATE docs_chunks SET embedding = CAST(? AS vector), embedding_model = ? WHERE id = ?', [json_encode(cacheVector(1)), 'ollama/bge-m3', $chunkId]);
    $version->forget();

    expect($version->current())->toBe($complete)
        ->and($version->hasPendingEmbeddings())->toBeFalse();
});

it('AC-3 store refuses every answer while any chunk lacks a vector for the current embedding model', function (): void {
    $candidate = cacheCandidate();
    cachePendingChunk();

    expect(cacheService()->store($candidate))->toBeFalse()
        ->and(DB::table('docs_answer_cache')->count())->toBe(0)
        ->and(Cache::store('array')->get('docs-answer:index-version'))->toBeString();
});

it('AC-3 storing again with a differently cased question updates the question and the embedding in one upsert', function (): void {
    cacheService()->store(cacheCandidate('how do i validate a request', cacheVector(1)));
    DB::table('docs_answer_cache')->update(['hit_count' => 3, 'created_at' => now()->subDays(2)]);
    $id = DB::table('docs_answer_cache')->value('id');

    cacheService()->store(cacheCandidate('How Do I Validate A Request?', cacheVectorAt(0.9)));

    $row = DB::table('docs_answer_cache')->first();
    $similarity = cacheService()->lookupSemantic(cacheVectorAt(0.9), CACHE_PROMPT, CACHE_MODEL)?->similarity;

    expect(DB::table('docs_answer_cache')->count())->toBe(1)
        ->and($row->id)->toBe($id)
        ->and($row->question)->toBe('How Do I Validate A Request?')
        ->and($row->hit_count)->toBe(3)
        ->and(CarbonImmutable::parse($row->created_at)->isAfter(now()->subMinute()))->toBeTrue()
        ->and($similarity)->toEqualWithDelta(1.0, 1e-6);
});

it('AC-2 the semantic query orders by distance alone so the HNSW index can serve it', function (): void {
    cacheService()->store(cacheCandidate());
    DB::enableQueryLog();

    cacheService()->lookupSemantic(cacheVector(1), CACHE_PROMPT, CACHE_MODEL);

    $sql = collect(DB::getQueryLog())->pluck('query')->first(fn (string $query): bool => str_contains($query, '<=>'));
    expect(strtolower($sql))->toContain('order by embedding <=> cast(? as vector) limit 1')
        ->and(strtolower($sql))->not->toMatch('/order by[^)]*\), *"?id"?/');
});
