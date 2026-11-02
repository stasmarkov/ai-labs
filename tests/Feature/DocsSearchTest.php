<?php

declare(strict_types=1);

use App\Models\AiRun;
use App\Models\DocsChunk;
use App\Models\DocsDocument;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * A 1024-number vector at the given angle from the first axis, so cosine similarity to [1, 0, ...] is cos($angle).
 *
 * @return list<float>
 */
function docsVector(float $angle): array
{
    return [cos($angle), sin($angle), ...array_fill(0, 1022, 0.0)];
}

/**
 * Store one chunk of a new document; a null angle leaves the chunk without a vector.
 */
function docsChunk(string $path, string $body, ?float $angle = null, string $heading = 'Section', string $source = 'repo', ?string $model = null): DocsChunk
{
    $document = DocsDocument::create([
        'source' => $source,
        'path' => $path,
        'title' => $path,
        'content_hash' => hash('sha256', $path),
    ]);

    $chunk = $document->chunks()->create([
        'position' => 0,
        'heading' => $heading,
        'body' => $body,
        'content' => "{$path} › {$heading}\n\n{$body}",
        'token_estimate' => 10,
        'content_hash' => hash('sha256', $body),
    ]);

    if ($angle !== null) {
        DB::update(
            'UPDATE docs_chunks SET embedding = CAST(? AS vector), embedding_model = ? WHERE id = ?',
            [json_encode(docsVector($angle)), $model ?? config('ai.default_for_embeddings').'/'.config('ai.providers.'.config('ai.default_for_embeddings').'.models.embeddings.default'), $chunk->id],
        );
    }

    return $chunk;
}

function docsFakeQuery(): void
{
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => docsVector(0.0), $prompt->inputs));
}

it('AC-5 vector mode orders chunks by cosine similarity and uses the HNSW index', function (): void {
    $far = docsChunk('far.md', 'unrelated words', 1.2);
    $near = docsChunk('near.md', 'unrelated words', 0.1);
    $middle = docsChunk('middle.md', 'unrelated words', 0.6);
    docsChunk('none.md', 'unrelated words');
    docsFakeQuery();

    $plans = [];
    DB::listen(function ($query) use (&$plans): void {
        if (str_contains($query->sql, '<=>') && ! str_starts_with($query->sql, 'EXPLAIN')) {
            DB::transaction(function () use ($query, &$plans): void {
                DB::statement('SET LOCAL enable_seqscan = off');
                $plans[] = collect(DB::select('EXPLAIN '.$query->sql, $query->bindings))->map(fn (object $row): string => (string) ((array) $row)['QUERY PLAN'])->implode("\n");
            });
        }
    });

    $data = $this->getJson('/api/docs/search?q=anything&mode=vector&k=10')->assertOk()->json('data');

    expect(array_column($data, 'id'))->toBe([$near->id, $middle->id, $far->id])
        ->and($data[0]['vector_rank'])->toBe(1)
        ->and($data[0]['vector_similarity'])->toEqual(round(cos(0.1), 6))
        ->and($data[0]['score'])->toEqual(round(cos(0.1), 6))
        ->and($data[0]['text_rank'])->toBeNull()
        ->and($data[0]['text_score'])->toBeNull()
        ->and($plans)->not->toBeEmpty()
        ->and($plans[0])->toContain(DocsChunk::EMBEDDING_INDEX);
});

it('AC-5 vector mode ignores chunks embedded with another model', function (): void {
    $current = docsChunk('current.md', 'text', 0.5);
    docsChunk('old.md', 'text', 0.0, model: 'other/model');
    docsFakeQuery();

    $this->getJson('/api/docs/search?q=anything&mode=vector')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $current->id);
});

it('AC-5 text mode finds an exact identifier without calling the model', function (): void {
    $target = docsChunk('perf.md', 'Call flushpackets after every frame to keep latency low.', 0.9);
    docsChunk('other.md', 'A plain chapter about caching and queues.', 0.1);
    docsFakeQuery();

    $data = $this->getJson('/api/docs/search?q=flushpackets&mode=text')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($target->id)
        ->and($data[0]['text_rank'])->toBe(1)
        ->and($data[0]['text_score'])->toBeGreaterThan(0)
        ->and($data[0]['score'])->toEqual($data[0]['text_score'])
        ->and($data[0]['vector_rank'])->toBeNull()
        ->and($data[0]['vector_similarity'])->toBeNull();

    Embeddings::assertNothingGenerated();
});

it('AC-5 text mode answers an empty list for a query with no usable words', function (): void {
    docsChunk('perf.md', 'Call flushpackets after every frame.');

    $this->getJson('/api/docs/search?q=the&mode=text')->assertOk()->assertJsonPath('data', []);
});

it('AC-5 hybrid scores are reciprocal rank fusion of both lists', function (): void {
    config(['ai.docs.rrf_depth' => 3]);
    $both = docsChunk('both.md', 'flushpackets flushpackets flushpackets', 0.1);
    $vectorOnly = docsChunk('vector.md', 'nothing relevant here', 0.2);
    $textOnly = docsChunk('text.md', 'a long text about flushpackets among many other words in this chunk', 3.0);
    docsChunk('far.md', 'nothing relevant here', 2.5);
    docsChunk('farther.md', 'nothing relevant here', 2.6);
    docsFakeQuery();

    $vectorList = collect($this->getJson('/api/docs/search?q=flushpackets&mode=vector&k=3')->json('data'))->keyBy('id');
    $textList = collect($this->getJson('/api/docs/search?q=flushpackets&mode=text&k=3')->json('data'))->keyBy('id');
    $hybrid = collect($this->getJson('/api/docs/search?q=flushpackets&mode=hybrid&k=3')->assertOk()->json('data'));

    expect($vectorList->keys()->all())->toBe([$both->id, $vectorOnly->id, $vectorList->keys()->all()[2]])
        ->and($textList->keys()->all())->toBe([$both->id, $textOnly->id])
        ->and($hybrid)->toHaveCount(3);

    foreach ($hybrid as $row) {
        $vectorRank = $vectorList->get($row['id'])['vector_rank'] ?? null;
        $textRank = $textList->get($row['id'])['text_rank'] ?? null;
        $expected = ($vectorRank === null ? 0.0 : 1 / (60 + $vectorRank)) + ($textRank === null ? 0.0 : 1 / (60 + $textRank));

        expect($row['vector_rank'])->toBe($vectorRank)
            ->and($row['text_rank'])->toBe($textRank)
            ->and($row['score'])->toEqual(round($expected, 6));
    }

    $byId = $hybrid->keyBy('id');
    expect($hybrid->first()['id'])->toBe($both->id)
        ->and($byId[$both->id]['score'])->toEqual(round(1 / 61 + 1 / 61, 6))
        ->and($byId[$vectorOnly->id]['text_rank'])->toBeNull()
        ->and($byId[$vectorOnly->id]['text_score'])->toBeNull()
        ->and($byId[$textOnly->id]['vector_rank'])->toBeNull()
        ->and($byId[$textOnly->id]['vector_similarity'])->toBeNull()
        ->and($byId[$textOnly->id]['score'])->toEqual(round(1 / 62, 6));
});

it('AC-5 hybrid fills k places even when the fusion depth is smaller than k', function (): void {
    config(['ai.docs.rrf_depth' => 2]);
    foreach (range(1, 5) as $i) {
        docsChunk("doc{$i}.md", "flushpackets note {$i}", 0.1 * $i);
    }
    docsFakeQuery();

    $this->getJson('/api/docs/search?q=flushpackets&mode=hybrid&k=5')->assertOk()->assertJsonCount(5, 'data');
});

it('AC-5 hybrid falls back to the vector list when full text matches nothing', function (): void {
    $chunk = docsChunk('a.md', 'plain words', 0.1);
    docsFakeQuery();

    $this->getJson('/api/docs/search?q=zzzunknownzzz')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $chunk->id)
        ->assertJsonPath('data.0.vector_rank', 1)
        ->assertJsonPath('data.0.text_rank', null);
});

it('AC-5 k limits the number of chunks and defaults to six', function (): void {
    foreach (range(1, 8) as $index) {
        docsChunk("c{$index}.md", 'plain words', $index * 0.1);
    }

    docsFakeQuery();

    $this->getJson('/api/docs/search?q=anything&mode=vector')->assertJsonCount(6, 'data');
    $this->getJson('/api/docs/search?q=anything&mode=vector&k=2')->assertJsonCount(2, 'data');
    $this->getJson('/api/docs/search?q=anything&mode=hybrid&k=3')->assertJsonCount(3, 'data');
});

it('AC-6 the search endpoint returns ranked chunks', function (): void {
    $chunk = docsChunk('docs/guide.md', str_repeat('x', 400), 0.0, 'Guide › Setup', 'laravel');
    docsFakeQuery();

    $response = $this->getJson('/api/docs/search?q=setup');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => [['id', 'source', 'path', 'heading', 'snippet', 'score', 'vector_rank', 'vector_similarity', 'text_rank', 'text_score']]])
        ->assertJsonMissingPath('meta');

    $row = $response->json('data.0');
    expect($row['id'])->toBe($chunk->id)
        ->and($row['source'])->toBe('laravel')
        ->and($row['path'])->toBe('docs/guide.md')
        ->and($row['heading'])->toBe('Guide › Setup')
        ->and(mb_strlen($row['snippet']))->toBe(301)
        ->and($row['snippet'])->toEndWith('…')
        ->and($row['snippet'])->not->toContain('docs/guide.md');
});

it('AC-6 the snippet keeps a short body whole', function (): void {
    docsChunk('short.md', 'Short body.', 0.0);
    docsFakeQuery();

    $this->getJson('/api/docs/search?q=short')->assertJsonPath('data.0.snippet', 'Short body.');
});

it('AC-6 the search endpoint answers an empty list when nothing is indexed', function (): void {
    docsFakeQuery();

    $this->getJson('/api/docs/search?q=anything')->assertOk()->assertJsonPath('data', []);
});

it('AC-6 the search endpoint rejects invalid input with 422 JSON', function (string $url): void {
    docsFakeQuery();

    $this->get($url, ['Accept' => 'text/html'])
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    Embeddings::assertNothingGenerated();
})->with([
    'missing q' => '/api/docs/search',
    'blank q' => '/api/docs/search?q=%20%20',
    'too long q' => '/api/docs/search?q='.str_repeat('a', 201),
    'unknown mode' => '/api/docs/search?q=a&mode=magic',
    'k zero' => '/api/docs/search?q=a&k=0',
    'k too big' => '/api/docs/search?q=a&k=21',
    'k not a number' => '/api/docs/search?q=a&k=many',
    'NUL byte in q' => '/api/docs/search?q=a%00b&mode=text',
    'invalid UTF-8 in q' => '/api/docs/search?q=a%C3%28&mode=text',
]);

it('AC-6 the search endpoint accepts k of 1 and 20', function (): void {
    docsFakeQuery();

    $this->getJson('/api/docs/search?q=a&k=1')->assertOk();
    $this->getJson('/api/docs/search?q=a&k=20')->assertOk();
});

it('AC-6 the search endpoint logs the query embedding', function (): void {
    docsChunk('a.md', 'plain words', 0.0);
    docsFakeQuery();

    $this->getJson('/api/docs/search?q=plain&mode=hybrid')->assertOk();

    $run = AiRun::sole();
    expect($run->agent)->toBe('embeddings:query')
        ->and($run->inputs)->toBe(1)
        ->and($run->status)->toBe('completed');
});

it('AC-6 the search endpoint answers 503 JSON when the model fails but text mode still works', function (): void {
    docsChunk('a.md', 'plain words', 0.0);
    Embeddings::fake(fn () => throw new RuntimeException('model is down'));

    $this->getJson('/api/docs/search?q=plain&mode=vector')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Docs search is unavailable.');
    $this->getJson('/api/docs/search?q=plain&mode=hybrid')->assertStatus(503);
    $this->getJson('/api/docs/search?q=plain&mode=text')->assertOk()->assertJsonCount(1, 'data');
});

it('AC-6 the search endpoint answers 503 JSON when the query vector has the wrong length', function (): void {
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [0.1, 0.2], $prompt->inputs));

    $this->getJson('/api/docs/search?q=plain')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Docs search is unavailable.');
});

it('AC-6 the search endpoint is limited per IP and answers 429 as JSON', function (): void {
    config(['ai.docs.search_per_minute' => 2]);
    docsFakeQuery();

    $this->getJson('/api/docs/search?q=one')->assertOk();
    $this->getJson('/api/docs/search?q=two')->assertOk();
    $limited = $this->get('/api/docs/search?q=three', ['Accept' => 'text/html']);

    $limited->assertStatus(429);
    expect($limited->headers->get('Content-Type'))->toContain('application/json');
});

it('AC-6 docs:search prints a table of ranked chunks', function (): void {
    docsChunk('docs/guide.md', 'Call flushpackets after every frame.', 0.0, 'Guide');
    docsFakeQuery();

    $this->artisan('docs:search', ['query' => 'flushpackets', '--mode' => 'hybrid', '--k' => 3])
        ->expectsOutputToContain('docs/guide.md')
        ->assertSuccessful();
});

it('AC-6 docs:search rejects a bad mode or k with exit 1', function (array $options): void {
    $this->artisan('docs:search', ['query' => 'x', ...$options])->assertFailed();
})->with([
    'mode' => [['--mode' => 'magic']],
    'k low' => [['--k' => 0]],
    'k high' => [['--k' => 21]],
]);

it('AC-6 docs:search says so when the model is unavailable', function (): void {
    Embeddings::fake(fn () => throw new RuntimeException('model is down'));

    $this->artisan('docs:search', ['query' => 'x', '--mode' => 'vector'])
        ->expectsOutputToContain('Docs search is unavailable.')
        ->assertFailed();
});
