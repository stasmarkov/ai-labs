<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\DocsDocument;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['ai.default_for_embeddings' => 'ollama', 'ai.features.embeddings-listings.provider' => null, 'ai.features.embeddings-docs.provider' => null]);
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => [1.0, ...array_fill(0, 1023, 0.0)], $prompt->inputs));
});

function switchListingEmbedded(string $model): void
{
    $id = Listing::factory()->for(Dealer::factory())->create()->id;
    test()->artisan('listings:describe-all')->assertSuccessful();
    DB::update('UPDATE listing_documents SET embedding = CAST(? AS vector), embedding_model = ? WHERE listing_id = ?', [json_encode([1.0, ...array_fill(0, 1023, 0.0)]), $model, $id]);
}

function switchDocsEmbedded(string $model): void
{
    $document = DocsDocument::create(['source' => 'repo', 'path' => 'a.md', 'title' => 'a', 'content_hash' => 'h']);
    $chunk = $document->chunks()->create(['position' => 0, 'heading' => 'S', 'body' => 'rate limiting', 'content' => 'rate limiting', 'token_estimate' => 3, 'content_hash' => 'c']);
    DB::update('UPDATE docs_chunks SET embedding = CAST(? AS vector), embedding_model = ? WHERE id = ?', [json_encode([1.0, ...array_fill(0, 1023, 0.0)]), $model, $chunk->id]);
}

it('AC-5 semantic search answers 409 index_not_built when the vectors come from another model', function (): void {
    switchListingEmbedded('openai/text-embedding-3-small');

    $this->getJson('/api/listings/semantic?q=family+car')
        ->assertStatus(409)
        ->assertJsonPath('code', 'index_not_built')
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'listings:embed'));
    Embeddings::assertNothingGenerated();
});

it('AC-5 docs search and docs ask answer 409 index_not_built, ask before any stream', function (): void {
    switchDocsEmbedded('openai/text-embedding-3-small');

    $this->getJson('/api/docs/search?q=rate+limiting&mode=vector')->assertStatus(409)->assertJsonPath('code', 'index_not_built');
    $this->getJson('/api/docs/search?q=rate+limiting&mode=hybrid')->assertStatus(409)->assertJsonPath('code', 'index_not_built');
    $this->postJson('/api/docs/ask', ['q' => 'how does rate limiting work'])->assertStatus(409)->assertJsonPath('code', 'index_not_built');
    $this->getJson('/api/docs/search?q=rate+limiting&mode=text')->assertOk();
});

it('AC-5 an empty index keeps today response and MNC-3 the configured model is searched as before', function (): void {
    $this->getJson('/api/listings/semantic?q=family+car')->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/docs/search?q=rate+limiting&mode=vector')->assertOk()->assertJsonPath('data', []);

    switchListingEmbedded('ollama/bge-m3');
    switchDocsEmbedded('ollama/bge-m3');

    $this->getJson('/api/listings/semantic?q=family+car')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/docs/search?q=rate+limiting&mode=vector')->assertOk()->assertJsonCount(1, 'data');
});

it('AC-5 listings:embed and docs:index exit non-zero before any call when the dimensions are not 1024', function (): void {
    config(['ai.providers.ollama.models.embeddings.dimensions' => 768]);

    $this->artisan('listings:embed')->expectsOutputToContain('768 dimensions')->assertExitCode(1);
    $this->artisan('docs:index')->expectsOutputToContain('768 dimensions')->assertExitCode(1);
    Embeddings::assertNothingGenerated();
});

it('AC-5 MNC-3 semantic search returns only listings embedded with the configured model', function (): void {
    switchListingEmbedded('ollama/bge-m3');
    $kept = DB::table('listing_documents')->value('listing_id');
    switchListingEmbedded('openai/text-embedding-3-small');

    $ids = collect($this->getJson('/api/listings/semantic?q=family+car')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$kept]);
});

it('AC-2 search endpoints answer 503 provider_not_configured for a key-less embeddings provider and call nothing', function (): void {
    config([
        'ai.providers.openai.key' => '',
        'ai.features.embeddings-listings.provider' => 'openai',
        'ai.features.embeddings-docs.provider' => 'openai',
    ]);

    $this->getJson('/api/listings/semantic?q=family+car')->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
    $this->getJson('/api/docs/search?q=rate+limiting&mode=vector')->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
    $this->postJson('/api/docs/ask', ['q' => 'how does rate limiting work'])->assertStatus(503)->assertJsonPath('code', 'provider_not_configured');
    Embeddings::assertNothingGenerated();
});

it('AC-5 the docs 409 names the docs re-index command', function (): void {
    switchDocsEmbedded('openai/text-embedding-3-small');

    $this->getJson('/api/docs/search?q=rate+limiting&mode=vector')
        ->assertStatus(409)
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'docs:index'));
});
