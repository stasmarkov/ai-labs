<?php

declare(strict_types=1);

use App\Contracts\SemanticBenchRepositoryInterface;
use App\Contracts\SemanticSearchRepositoryInterface;
use App\Models\Dealer;
use App\Models\Listing;
use App\Models\ListingDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

/**
 * @return list<float>
 */
function benchVector(float $angle): array
{
    return [cos($angle), sin($angle), ...array_fill(0, 1022, 0.0)];
}

/**
 * @return list<int> listing ids
 */
function benchEmbeddedListings(int $count): array
{
    $ids = Listing::factory()->count($count)->for(Dealer::factory())->create()->pluck('id')->all();
    test()->artisan('listings:describe-all')->assertSuccessful();

    foreach ($ids as $index => $id) {
        DB::update(
            'UPDATE listing_documents SET embedding = CAST(? AS vector), embedding_model = ?, body_type = ? WHERE listing_id = ?',
            [json_encode(benchVector($index * 0.1)), 'ollama/bge-m3', $index < 8 ? 'estate' : 'coupe', $id],
        );
    }

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => benchVector(0.0), $prompt->inputs));

    return $ids;
}

function benchPaths(): array
{
    $directory = storage_path('framework/testing/search-bench');
    File::ensureDirectoryExists($directory);
    File::put("{$directory}/queries.txt", "family car with a big boot\nsmall cheap city car\n\nfast coupe\n");

    return [$directory.'/queries.txt', $directory.'/out.json'];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/search-bench'));
});

it('AC-8 search-bench records latency, recall and the body type count', function (): void {
    Http::fake(['*/api/version' => Http::response(['version' => '0.40.13'])]);
    config()->set('ai.providers.ollama.num_parallel', 4);
    benchEmbeddedListings(12);
    [$queries, $output] = benchPaths();

    $this->artisan('listings:search-bench', ['--queries' => $queries, '--repeat' => 2, '--output' => $output])
        ->assertSuccessful();

    $report = json_decode(File::get($output), true, flags: JSON_THROW_ON_ERROR);

    expect($report)->toHaveKeys(['model', 'provider', 'pgvector', 'ollama_version', 'ollama_num_parallel', 'queries', 'repeat', 'samples', 'embed_ms', 'db_ms', 'total_ms', 'recall_at_10', 'ef_search', 'index_build_seconds', 'index_bytes', 'probe'])
        ->and($report['model'])->toBe('bge-m3')
        ->and($report['ollama_version'])->toBe('0.40.13')
        ->and($report['ollama_num_parallel'])->toBe(4)
        ->and($report['queries'])->toBe(3)
        ->and($report['samples'])->toBe(6)
        ->and($report['embed_ms'])->toHaveKeys(['p50', 'p95'])
        ->and($report['db_ms']['p95'])->toBeGreaterThanOrEqual($report['db_ms']['p50'])
        ->and($report['recall_at_10'])->toEqual(1.0)
        ->and($report['ef_search'])->toBe(config('ai.embeddings.ef_search'))
        ->and(array_keys($report['ef_sweep']))->toBe([40, 100, 200, 400])
        ->and($report['index_build_seconds'])->toBeNull()
        ->and($report['probe']['query'])->toBe('family car with a big boot')
        ->and($report['probe']['total'])->toBe(10)
        ->and($report['probe']['matching'])->toBe(8);
});

it('AC-8 search-bench --index rebuilds the HNSW index and times it', function (): void {
    benchEmbeddedListings(3);
    [$queries, $output] = benchPaths();

    $this->artisan('listings:search-bench', ['--queries' => $queries, '--repeat' => 1, '--index' => true, '--output' => $output])
        ->assertSuccessful();

    $report = json_decode(File::get($output), true, flags: JSON_THROW_ON_ERROR);

    expect($report['index_build_seconds'])->toBeNumeric()->toBeGreaterThanOrEqual(0.0)
        ->and($report['index_bytes'])->toBeGreaterThan(0)
        ->and(DB::scalar('SELECT count(*) FROM pg_indexes WHERE indexname = ?', [ListingDocument::EMBEDDING_INDEX]))->toBe(1);
});

it('AC-8 search-bench refuses a missing query file and an empty table', function (): void {
    $this->artisan('listings:search-bench', ['--queries' => '/nonexistent/queries.txt'])->assertFailed();

    [$queries] = benchPaths();
    $this->artisan('listings:search-bench', ['--queries' => $queries])->assertFailed();
});

/**
 * @return array{string, array<int, mixed>} the nearest-neighbour statement the callback ran
 */
function benchCapturedNearestQuery(Closure $run): array
{
    $captured = [];
    DB::listen(function ($query) use (&$captured): void {
        if ($captured === [] && str_contains($query->sql, '<=>')) {
            $captured = [$query->sql, $query->bindings];
        }
    });

    $run();

    return $captured;
}

function benchExplain(array $query, bool $exactScan = false): string
{
    return DB::transaction(function () use ($query, $exactScan): string {
        DB::statement('RESET enable_seqscan');
        DB::statement('RESET enable_indexscan');
        DB::statement($exactScan ? 'SET LOCAL enable_indexscan = off' : 'SET LOCAL enable_seqscan = off');

        return collect(DB::select('EXPLAIN '.$query[0], $query[1]))
            ->map(fn (object $row): string => (string) ((array) $row)['QUERY PLAN'])
            ->implode("\n");
    });
}

it('AC-8 the endpoint and bench approximate queries use the HNSW index and the exact scan does not', function (): void {
    benchEmbeddedListings(12);
    $vector = benchVector(0.0);

    $endpoint = benchCapturedNearestQuery(fn () => app(SemanticSearchRepositoryInterface::class)->nearest($vector, 10, 400, 'ollama/bge-m3'));
    $approximate = benchCapturedNearestQuery(fn () => app(SemanticBenchRepositoryInterface::class)->approximateIds($vector, 10, 40));
    $exact = benchCapturedNearestQuery(fn () => app(SemanticBenchRepositoryInterface::class)->exactIds($vector, 10));

    expect(benchExplain($endpoint))->toContain(ListingDocument::EMBEDDING_INDEX)
        ->and(benchExplain($approximate))->toContain(ListingDocument::EMBEDDING_INDEX)
        ->and(benchExplain($exact, exactScan: true))->not->toContain(ListingDocument::EMBEDDING_INDEX);
});

it('AC-8 the exact scan leaves the index scan setting as it was, even inside a transaction', function (): void {
    benchEmbeddedListings(3);
    $before = DB::scalar('SHOW enable_indexscan');

    app(SemanticBenchRepositoryInterface::class)->exactIds(benchVector(0.0), 10);

    expect(DB::scalar('SHOW enable_indexscan'))->toBe($before);
});

it('AC-8 an index rebuild leaves exactly one HNSW index under the usual name', function (): void {
    benchEmbeddedListings(3);

    app(SemanticBenchRepositoryInterface::class)->rebuildIndex();
    app(SemanticBenchRepositoryInterface::class)->rebuildIndex();

    expect(DB::scalar("SELECT count(*) FROM pg_indexes WHERE tablename = 'listing_documents' AND indexdef LIKE '%hnsw%'"))->toBe(1)
        ->and(DB::scalar('SELECT count(*) FROM pg_indexes WHERE indexname = ?', [ListingDocument::EMBEDDING_INDEX]))->toBe(1);
});

it('AC-8 the bench measures the HNSW index even at a high ef_search', function (): void {
    benchEmbeddedListings(12);
    $vector = benchVector(0.0);
    $plans = [];
    DB::listen(function ($query) use (&$plans): void {
        if (str_contains($query->sql, '<=>') && ! str_starts_with($query->sql, 'EXPLAIN')) {
            $plans[] = collect(DB::select('EXPLAIN '.$query->sql, $query->bindings))->map(fn (object $row): string => (string) ((array) $row)['QUERY PLAN'])->implode("\n");
        }
    });

    app(SemanticBenchRepositoryInterface::class)->approximateIds($vector, 10, 1000);

    expect($plans)->not->toBeEmpty()
        ->and($plans[0])->toContain(ListingDocument::EMBEDDING_INDEX);
});

it('AC-6 the endpoint query uses the HNSW index at the configured ef_search', function (): void {
    benchEmbeddedListings(12);
    $plans = [];
    DB::listen(function ($query) use (&$plans): void {
        if (str_contains($query->sql, '<=>') && ! str_starts_with($query->sql, 'EXPLAIN')) {
            $plans[] = collect(DB::select('EXPLAIN '.$query->sql, $query->bindings))->map(fn (object $row): string => (string) ((array) $row)['QUERY PLAN'])->implode("\n");
        }
    });

    app(SemanticSearchRepositoryInterface::class)->nearest(benchVector(0.0), 10, (int) config('ai.embeddings.ef_search'), 'ollama/bge-m3');

    expect(config('ai.embeddings.ef_search'))->toBe(400)
        ->and($plans)->not->toBeEmpty()
        ->and($plans[0])->toContain(ListingDocument::EMBEDDING_INDEX);
});
