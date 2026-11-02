<?php

declare(strict_types=1);

use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;
use App\Models\ListingDocument;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * A 1024-number vector at the given angle from the first axis, so cosine similarity to [1, 0, ...] is cos($angle).
 *
 * @return list<float>
 */
function semanticVector(float $angle): array
{
    return [cos($angle), sin($angle), ...array_fill(0, 1022, 0.0)];
}

function semanticStore(int $listingId, float $angle, ?string $bodyType = null): void
{
    DB::update(
        'UPDATE listing_documents SET embedding = CAST(? AS vector), embedding_model = ?, body_type = COALESCE(?, body_type) WHERE listing_id = ?',
        [json_encode(semanticVector($angle)), 'ollama/bge-m3', $bodyType, $listingId],
    );
}

function semanticFakeQuery(): void
{
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => semanticVector(0.0), $prompt->inputs));
}

/**
 * @return list<int> listing ids, in creation order
 */
function semanticListings(int $count): array
{
    $ids = Listing::factory()->count($count)->for(Dealer::factory())->create()->pluck('id')->all();
    test()->artisan('listings:describe-all')->assertSuccessful();

    return $ids;
}

it('AC-6 semantic search returns the ten most similar listings', function (): void {
    $ids = semanticListings(12);

    foreach ($ids as $index => $id) {
        semanticStore($id, $index * 0.1);
    }

    semanticFakeQuery();

    $response = $this->getJson('/api/listings/semantic?q=family+car');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(10, 'data')
        ->assertJsonStructure(['data' => [['id', 'year', 'make', 'model', 'body_type', 'price_cents', 'currency', 'city', 'description', 'similarity']]]);

    $data = $response->json('data');
    expect(array_column($data, 'id'))->toBe(array_slice($ids, 0, 10))
        ->and($data[0]['similarity'])->toEqual(1.0)
        ->and($data[1]['similarity'])->toEqual(round(cos(0.1), 4))
        ->and($data[0]['description'])->toBe(ListingDocument::findOrFail($ids[0])->description)
        ->and(array_column($data, 'similarity'))->toBe(collect(array_column($data, 'similarity'))->sortDesc()->values()->all());
});

it('AC-6 semantic search skips listings without a vector', function (): void {
    $ids = semanticListings(3);
    semanticStore($ids[0], 0.5);
    semanticStore($ids[2], 0.2);
    semanticFakeQuery();

    $this->getJson('/api/listings/semantic?q=anything')
        ->assertOk()
        ->assertJsonPath('data.0.id', $ids[2])
        ->assertJsonPath('data.1.id', $ids[0])
        ->assertJsonCount(2, 'data');
});

it('AC-6 semantic search answers an empty list when nothing is embedded', function (): void {
    semanticListings(2);
    semanticFakeQuery();

    $this->getJson('/api/listings/semantic?q=anything')->assertOk()->assertExactJson([
        'success' => true,
        'status' => 200,
        'message' => 'Listings retrieved',
        'data' => [],
    ]);
});

it('AC-6 semantic search rejects a missing, empty, blank or too long q with 422 JSON', function (string $url): void {
    semanticFakeQuery();

    $this->get(str_replace('q=X', 'q='.str_repeat('a', 201), $url), ['Accept' => 'text/html'])
        ->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['errors' => ['q']]);

    Embeddings::assertNothingGenerated();
})->with([
    'missing' => '/api/listings/semantic',
    'empty' => '/api/listings/semantic?q=',
    'blank' => '/api/listings/semantic?q=%20%20',
    'too long' => '/api/listings/semantic?q=X',
]);

it('AC-6 semantic search accepts 200 characters and rejects 201', function (): void {
    semanticFakeQuery();

    $this->getJson('/api/listings/semantic?q='.str_repeat('é', 200))->assertOk();
    $this->getJson('/api/listings/semantic?q='.str_repeat('é', 201))->assertStatus(422);
});

it('AC-6 semantic search logs the query embedding', function (): void {
    semanticListings(1);
    semanticFakeQuery();

    $this->getJson('/api/listings/semantic?q=big+boot')->assertOk();

    $run = AiRun::firstOrFail();
    expect(AiRun::count())->toBe(1)
        ->and($run->agent)->toBe('embeddings:query')
        ->and($run->inputs)->toBe(1)
        ->and($run->status)->toBe('completed');
});

it('AC-6 semantic search answers 503 JSON when the model fails and logs it', function (): void {
    Embeddings::fake(fn () => throw new RuntimeException('model is down'));

    $this->getJson('/api/listings/semantic?q=big+boot')
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Semantic search is unavailable.');

    expect(AiRun::where('agent', 'embeddings:query')->where('status', 'failed')->count())->toBe(1);
});

it('AC-6 semantic search answers 503 JSON when the query vector has the wrong length', function (): void {
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [0.1, 0.2], $prompt->inputs));

    $this->getJson('/api/listings/semantic?q=big+boot')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Semantic search is unavailable.');

    $run = AiRun::query()->where('agent', 'embeddings:query')->sole();
    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('1024')->toContain('2');
});

it('AC-6 semantic search is limited per IP and answers 429 as JSON', function (): void {
    config(['ai.semantic_per_minute' => 2]);
    semanticFakeQuery();

    $this->getJson('/api/listings/semantic?q=one')->assertOk();
    $this->getJson('/api/listings/semantic?q=two')->assertOk();
    $limited = $this->get('/api/listings/semantic?q=three', ['Accept' => 'text/html']);

    $limited->assertStatus(429);
    expect($limited->headers->get('Content-Type'))->toContain('application/json');
});

it('AC-7 the nearest vectors come first', function (): void {
    $ids = semanticListings(12);
    $roomy = ['estate', 'SUV', 'MPV', 'SUV', 'estate', 'MPV', 'SUV', 'estate'];

    foreach ($ids as $index => $id) {
        $roomyBody = $roomy[$index] ?? null;
        semanticStore($id, $roomyBody === null ? 1.0 + $index * 0.01 : $index * 0.01, $roomyBody ?? 'hatchback');
    }

    semanticFakeQuery();

    $bodies = array_column($this->getJson('/api/listings/semantic?q=family+car+with+a+big+boot')->assertOk()->json('data'), 'body_type');

    expect(array_slice($bodies, 0, 8))->each->toBeIn(['estate', 'SUV', 'MPV'])
        ->and(array_slice($bodies, 8))->each->toBe('hatchback');
});
