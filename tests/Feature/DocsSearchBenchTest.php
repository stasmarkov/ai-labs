<?php

declare(strict_types=1);

use App\Models\AiRun;
use App\Models\DocsChunk;
use App\Models\DocsDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * @return list<float>
 */
function benchDocsVector(float $angle): array
{
    return [cos($angle), sin($angle), ...array_fill(0, 1022, 0.0)];
}

function benchDocsChunk(string $source, string $path, string $body, float $angle, int $tokens = 100): DocsChunk
{
    $document = DocsDocument::create(['source' => $source, 'path' => $path, 'title' => $path, 'content_hash' => hash('sha256', "{$source}:{$path}")]);
    $chunk = $document->chunks()->create([
        'position' => 0,
        'heading' => 'Section',
        'body' => $body,
        'content' => "Document: {$path} › Section: Section\n\n{$body}",
        'token_estimate' => $tokens,
        'oversize' => $tokens > 500,
        'content_hash' => hash('sha256', $body),
    ]);

    DB::update(
        'UPDATE docs_chunks SET embedding = CAST(? AS vector), embedding_model = ? WHERE id = ?',
        [json_encode(benchDocsVector($angle)), 'ollama/bge-m3', $chunk->id],
    );

    return $chunk;
}

/**
 * Three embedded chunks; every query embeds to the first axis, so the nearest is the learning guide.
 */
function benchDocsFixture(): string
{
    benchDocsChunk('repo', 'docs/learning-guide.md', 'tune flushpackets to stream output', 0.0, 120);
    benchDocsChunk('laravel', 'scheduling.md', 'withoutOverlapping prevents a task from overlapping itself', 0.5, 600);
    benchDocsChunk('repo', 'docs/other.md', 'something else entirely', 1.0, 80);

    $directory = storage_path('framework/testing/docs-bench');
    File::ensureDirectoryExists($directory);
    File::put("{$directory}/queries.txt", implode("\n", [
        "identifier\tflushpackets\trepo:docs/learning-guide.md",
        "identifier\twithoutOverlapping\trepo:missing.md",
        "meaning\thow to stop a task overlapping\tlaravel:scheduling.md|repo:docs/other.md",
    ])."\n");

    Http::fake([
        '*/api/version' => Http::response(['version' => '0.40.13']),
        '*/api/embed' => fn ($request) => Http::response([
            'embeddings' => array_map(fn (): array => benchDocsVector(0.0), $request['input']),
            'prompt_eval_count' => 50 * count($request['input']),
        ]),
    ]);
    Process::fake([
        'git rev-parse HEAD' => Process::result("1111111111111111111111111111111111111111\n"),
        'git status --porcelain' => Process::result(" M README.md\n"),
    ]);

    return $directory;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/docs-bench'));
});

it('AC-7 the query file lists five identifier and five meaning queries with expected files', function (): void {
    $lines = array_filter(array_map('rtrim', file(base_path('bench/docs-queries.txt')) ?: []));
    $types = [];

    foreach ($lines as $line) {
        $columns = explode("\t", $line);

        expect($columns)->toHaveCount(3);
        foreach (explode('|', $columns[2]) as $expected) {
            expect($expected)->toMatch('/^(repo|laravel):\S+\.md$/');
        }
        $types[] = $columns[0];
    }

    expect(array_count_values($types))->toBe(['identifier' => 5, 'meaning' => 5]);
});

it('AC-7 search-bench records per query and mode whether the expected file is in the top 3', function (): void {
    $directory = benchDocsFixture();

    $this->artisan('docs:search-bench', ['--queries' => "{$directory}/queries.txt", '--output-dir' => $directory])
        ->assertSuccessful();

    $report = json_decode(File::get("{$directory}/search-bench.json"), true, flags: JSON_THROW_ON_ERROR);
    $rows = array_column($report['rows'], null, 'query');

    expect($report['k'])->toBe(3)
        ->and($report['rows'])->toHaveCount(3)
        ->and($rows['flushpackets']['type'])->toBe('identifier')
        ->and($rows['flushpackets']['expected'])->toBe(['repo:docs/learning-guide.md'])
        ->and($rows['flushpackets']['text'])->toBe(['passed' => true, 'rank' => 1, 'top' => ['repo:docs/learning-guide.md']])
        ->and($rows['flushpackets']['vector']['top'][0])->toBe('repo:docs/learning-guide.md')
        ->and($rows['flushpackets']['hybrid']['passed'])->toBeTrue()
        ->and($rows['withoutOverlapping']['text']['top'])->toBe(['laravel:scheduling.md'])
        ->and($rows['withoutOverlapping']['text']['passed'])->toBeFalse()
        ->and($rows['withoutOverlapping']['text']['rank'])->toBeNull()
        ->and($rows['how to stop a task overlapping']['vector']['rank'])->toBe(2)
        ->and($report['summary']['identifier'])->toBe(['total' => 2, 'vector' => 1, 'text' => 1, 'hybrid' => 1])
        ->and($report['summary']['meaning'])->toBe(['total' => 1, 'vector' => 1, 'text' => 0, 'hybrid' => 1])
        ->and($report['thresholds'])->toBe(['hybrid_identifier_min' => 4, 'hybrid_meaning_min' => 4, 'met' => false]);
});

it('AC-8 search-bench writes the index stats, sizes and the token estimate check', function (): void {
    $directory = benchDocsFixture();
    config()->set('ai.docs.laravel.meta_path', "{$directory}/laravel.json");
    File::put("{$directory}/laravel.json", json_encode(['branch' => '13.x', 'commit' => '625d1df'], JSON_THROW_ON_ERROR));

    foreach ([['a', 10], ['b', 2]] as [$id, $secondsAgo]) {
        DB::table('ai_runs')->insert(['invocation_id' => $id, 'agent' => 'embeddings:docs', 'provider' => 'ollama', 'model' => 'bge-m3', 'latency_ms' => 2000, 'status' => 'completed', 'inputs' => 32, 'created_at' => now()->subSeconds($secondsAgo)]);
    }

    $this->artisan('docs:search-bench', ['--queries' => "{$directory}/queries.txt", '--output-dir' => $directory, '--index-seconds' => '1.5', '--sample' => 2])
        ->assertSuccessful();

    $index = json_decode(File::get("{$directory}/index.json"), true, flags: JSON_THROW_ON_ERROR);

    expect($index['model'])->toBe('bge-m3')
        ->and($index['ollama_version'])->toBe('0.40.13')
        ->and($index['repo'])->toBe(['commit' => '1111111111111111111111111111111111111111', 'dirty' => true])
        ->and($index['laravel'])->toBe(['branch' => '13.x', 'commit' => '625d1df'])
        ->and($index['sources']['repo'])->toBe(['documents' => 2, 'chunks' => 2, 'tokens' => ['min' => 80, 'median' => 100, 'max' => 120], 'oversize' => 0])
        ->and($index['sources']['laravel'])->toBe(['documents' => 1, 'chunks' => 1, 'tokens' => ['min' => 600, 'median' => 600, 'max' => 600], 'oversize' => 1])
        ->and($index['indexing_seconds'])->toBe(1.5)
        ->and($index['embedding']['calls'])->toBe(2)
        ->and($index['embedding']['seconds'])->toEqualWithDelta(10.0, 0.5)
        ->and($index['sizes'])->toHaveKeys(['table_bytes', 'hnsw_bytes', 'gin_bytes', 'total_bytes'])
        ->and($index['sizes']['total_bytes'])->toBeGreaterThan(0)
        ->and($index['token_check']['chunks'])->toBe(3)
        ->and($index['token_check']['samples'])->toHaveCount(3)
        ->and($index['token_check']['mean_ratio'])->toBeFloat()
        ->and($index['token_check']['max_ratio'])->toBeGreaterThanOrEqual($index['token_check']['mean_ratio'])
        ->and($index['token_check']['mean_abs_error'])->toBeFloat();

    $sample = $index['token_check']['samples'][0];
    expect($sample)->toHaveKeys(['id', 'source', 'path', 'estimate', 'real'])
        ->and($sample['real'])->toBe(50);
    expect(AiRun::where('agent', 'embeddings:docs-token-check')->where('inputs', 1)->count())->toBe(3);
});

it('AC-8 the embedding window covers only the latest indexing run', function (): void {
    $directory = benchDocsFixture();
    config()->set('ai.docs.laravel.meta_path', "{$directory}/laravel.json");
    File::put("{$directory}/laravel.json", json_encode(['branch' => '13.x', 'commit' => '625d1df'], JSON_THROW_ON_ERROR));

    DocsDocument::query()->where('path', '!=', 'docs/other.md')->update(['indexed_at' => now()->subHours(3)]);
    DocsDocument::query()->where('path', 'docs/other.md')->update(['indexed_at' => now()->subSeconds(60)]);

    $rows = [
        ['old-a', 32, now()->subHours(3)->subSeconds(5)],
        ['old-b', 32, now()->subHours(3)],
        ['old-check', 1, now()->subHours(2)],
        ['new-a', 32, now()->subSeconds(40)],
        ['new-b', 1, now()->subSeconds(20)],
    ];
    foreach ($rows as [$id, $inputs, $at]) {
        DB::table('ai_runs')->insert(['invocation_id' => $id, 'agent' => 'embeddings:docs', 'provider' => 'ollama', 'model' => 'bge-m3', 'latency_ms' => 2000, 'status' => 'completed', 'inputs' => $inputs, 'tokens_in' => 10, 'created_at' => $at]);
    }

    DB::table('ai_runs')->insert(['invocation_id' => 'check', 'agent' => 'embeddings:docs-token-check', 'provider' => 'ollama', 'model' => 'bge-m3', 'latency_ms' => 100, 'status' => 'completed', 'inputs' => 1, 'tokens_in' => 10, 'created_at' => now()->subSeconds(10)]);

    $this->artisan('docs:search-bench', ['--queries' => "{$directory}/queries.txt", '--output-dir' => $directory, '--sample' => 1])
        ->assertSuccessful();

    $index = json_decode(File::get("{$directory}/index.json"), true, flags: JSON_THROW_ON_ERROR);

    expect($index['embedding']['calls'])->toBe(2)
        ->and($index['embedding']['tokens_in'])->toBe(20)
        ->and($index['embedding']['seconds'])->toEqualWithDelta(22.0, 1.0);
});

it('AC-8 search-bench fails clearly when nothing is embedded', function (): void {
    $this->artisan('docs:search-bench')
        ->expectsOutputToContain('No embedded doc chunks')
        ->assertFailed();
});
