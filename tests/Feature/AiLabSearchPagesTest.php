<?php

declare(strict_types=1);

use App\Models\Dealer;
use App\Models\DocsDocument;
use App\Models\Listing;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Embeddings;

/**
 * @return list<float>
 */
function labSearchVector(float $angle): array
{
    return [cos($angle), sin($angle), ...array_fill(0, 1022, 0.0)];
}

function labSearchFakeQuery(): void
{
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => labSearchVector(0.0), $prompt->inputs));
}

function labSearchSeed(): void
{
    $id = Listing::factory()->for(Dealer::factory())->create()->id;
    test()->artisan('listings:describe-all')->assertSuccessful();
    DB::update(
        'UPDATE listing_documents SET embedding = CAST(? AS vector), embedding_model = ? WHERE listing_id = ?',
        [json_encode(labSearchVector(0.1)), 'ollama/bge-m3', $id],
    );

    $document = DocsDocument::create(['source' => 'repo', 'path' => 'a.md', 'title' => 'a', 'content_hash' => hash('sha256', 'a')]);
    $chunk = $document->chunks()->create([
        'position' => 0,
        'heading' => 'Section',
        'body' => 'rotor brake pads',
        'content' => "a.md › Section\n\nrotor brake pads",
        'token_estimate' => 5,
        'content_hash' => hash('sha256', 'rotor'),
    ]);
    DB::update(
        'UPDATE docs_chunks SET embedding = CAST(? AS vector), embedding_model = ? WHERE id = ?',
        [json_encode(labSearchVector(0.2)), config('ai.default_for_embeddings').'/'.config('ai.providers.'.config('ai.default_for_embeddings').'.models.embeddings.default'), $chunk->id],
    );
}

it('AC-5 search endpoints send Server-Timing', function (): void {
    labSearchSeed();
    labSearchFakeQuery();

    expect($this->getJson('/api/listings/semantic?q=family')->assertOk()->headers->get('Server-Timing'))
        ->toMatch('/^embed;dur=\d+\.\d, db;dur=\d+\.\d$/');

    expect($this->getJson('/api/docs/search?q=rotor&mode=vector')->assertOk()->headers->get('Server-Timing'))
        ->toMatch('/^embed;dur=\d+\.\d, db;dur=\d+\.\d$/');

    expect($this->getJson('/api/docs/search?q=rotor&mode=hybrid')->assertOk()->headers->get('Server-Timing'))
        ->toMatch('/^embed;dur=\d+\.\d, db;dur=\d+\.\d$/');

    $text = $this->getJson('/api/docs/search?q=rotor&mode=text')->assertOk()->headers->get('Server-Timing');
    expect($text)->toMatch('/^db;dur=\d+\.\d$/')->not->toContain('embed');
});

it('AC-5 validation errors carry no Server-Timing header', function (): void {
    $this->getJson('/api/listings/semantic')->assertStatus(422)->assertHeaderMissing('Server-Timing');
});

it('MNC-2 search bodies are unchanged by Server-Timing', function (): void {
    labSearchSeed();
    labSearchFakeQuery();

    $this->getJson('/api/listings/semantic?q=family')
        ->assertOk()
        ->assertJsonPath('message', 'Listings retrieved')
        ->assertJsonStructure(['success', 'status', 'message', 'data' => [['id', 'year', 'make', 'model', 'body_type', 'price_cents', 'currency', 'city', 'description', 'similarity']]])
        ->assertJsonCount(4);

    $this->getJson('/api/docs/search?q=rotor&mode=text')
        ->assertOk()
        ->assertJsonPath('message', 'Chunks retrieved')
        ->assertJsonPath('data.0.path', 'a.md')
        ->assertJsonPath('data.0.text_rank', 1)
        ->assertJsonCount(4);
});

it('AC-5 the semantic page renders with its search settings', function (): void {
    $this->get('/ai/semantic')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Semantic')
            ->where('searchUrl', '/api/listings/semantic')
            ->where('maxQueryLength', 200));
});

it('AC-6 the docs page renders with its search settings', function (): void {
    $this->get('/ai/docs')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Docs')
            ->where('searchUrl', '/api/docs/search')
            ->where('maxQueryLength', 200)
            ->where('k', (int) config('ai.docs.k_default'))
            ->where('maxK', (int) config('ai.docs.k_max')));
});
