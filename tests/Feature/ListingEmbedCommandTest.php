<?php

declare(strict_types=1);

use App\Jobs\EmbedListingBatchJob;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;
use App\Models\ListingDocument;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

function describedListings(int $count): void
{
    Listing::factory()->count($count)->for(Dealer::factory())->create();
    test()->artisan('listings:describe-all')->assertSuccessful();
}

/**
 * @param  list<int>  $calls  receives the number of inputs of every model call
 */
function fakeEmbeddingsCounting(array &$calls, int $size = 1024): void
{
    Embeddings::fake(function ($prompt) use (&$calls, $size): array {
        $calls[] = count($prompt->inputs);

        return array_map(fn (): array => array_fill(0, $size, 0.5), $prompt->inputs);
    });
}

function embeddedCount(): int
{
    return ListingDocument::whereNotNull('embedding')->count();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function summaryPath(): string
{
    return sys_get_temp_dir().'/embed-summary-'.uniqid().'.json';
}

it('AC-2 embed sends one model call per batch and stores 1024-number vectors', function (): void {
    describedListings(5);
    $calls = [];
    fakeEmbeddingsCounting($calls);

    $this->artisan('listings:embed', ['--batch' => 2])->assertSuccessful();

    expect($calls)->toBe([2, 2, 1])
        ->and(AiRun::where('agent', 'embeddings')->count())->toBe(3)
        ->and(embeddedCount())->toBe(5);

    $document = ListingDocument::orderBy('listing_id')->firstOrFail();
    expect(DB::scalar('SELECT vector_dims(embedding) FROM listing_documents WHERE listing_id = ?', [$document->listing_id]))->toBe(1024)
        ->and($document->embedding_model)->toBe('ollama/bge-m3')
        ->and($document->embedded_text_hash)->toBe(hash('sha256', $document->description))
        ->and($document->embedded_at)->not->toBeNull();

    Embeddings::assertGenerated(fn ($prompt): bool => $prompt->provider->name() === 'ollama'
        && $prompt->model === 'bge-m3'
        && $prompt->dimensions === 1024);
});

it('AC-2 dispatches one job per batch on the embeddings queue', function (): void {
    describedListings(5);
    Queue::fake();

    $this->artisan('listings:embed', ['--batch' => 2])
        ->expectsOutputToContain('Queued 3 jobs for 5 listings')
        ->assertSuccessful();

    Queue::assertPushed(EmbedListingBatchJob::class, 3);
    Queue::assertPushedOn('embeddings', EmbedListingBatchJob::class);
    expect((new EmbedListingBatchJob([1]))->tries)->toBe(3);
});

it('AC-2 uses the batch size from config when --batch is not given', function (): void {
    describedListings(3);
    config(['ai.embeddings.batch' => 2]);
    $calls = [];
    fakeEmbeddingsCounting($calls);

    $this->artisan('listings:embed')->assertSuccessful();

    expect($calls)->toBe([2, 1]);
});

it('AC-3 a vector of the wrong length fails the batch', function (): void {
    describedListings(3);
    $calls = [];
    fakeEmbeddingsCounting($calls, 768);
    $failures = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failures): void {
        $failures[] = $event->exception->getMessage();
    });

    $this->artisan('listings:embed', ['--batch' => 10])->assertSuccessful();

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('768')->toContain('1024')
        ->and($calls)->toBe([3])
        ->and(embeddedCount())->toBe(0);
});

it('AC-3 a bad batch stores nothing for that batch but later batches still run', function (): void {
    describedListings(4);
    $call = 0;
    Embeddings::fake(function ($prompt) use (&$call): array {
        $size = ++$call === 1 ? 768 : 1024;

        return array_map(fn (): array => array_fill(0, $size, 0.5), $prompt->inputs);
    });

    $this->artisan('listings:embed', ['--batch' => 2])->assertSuccessful();

    expect(embeddedCount())->toBe(2);
});

it('AC-4 a second run embeds only what changed', function (): void {
    describedListings(5);
    $calls = [];
    fakeEmbeddingsCounting($calls);

    $this->artisan('listings:embed')->assertSuccessful();
    expect($calls)->toBe([5]);

    $this->artisan('listings:embed')->expectsOutputToContain('Queued 0 jobs')->assertSuccessful();
    expect($calls)->toBe([5]);

    $changed = ListingDocument::orderBy('listing_id')->firstOrFail();
    DB::table('listing_documents')->where('listing_id', $changed->listing_id)->update(['description' => 'A completely different text.']);

    $this->artisan('listings:embed')->assertSuccessful();
    expect($calls)->toBe([5, 1])
        ->and(ListingDocument::findOrFail($changed->listing_id)->embedded_text_hash)->toBe(hash('sha256', 'A completely different text.'));

    config(['ai.providers.ollama.models.embeddings.default' => 'other-embed']);

    $this->artisan('listings:embed')->assertSuccessful();
    expect($calls)->toBe([5, 1, 5])
        ->and(ListingDocument::where('embedding_model', 'ollama/other-embed')->count())->toBe(5);
});

it('AC-4 limit embeds only the first n listings by id', function (): void {
    describedListings(5);
    $calls = [];
    fakeEmbeddingsCounting($calls);

    $this->artisan('listings:embed', ['--limit' => 3])->assertSuccessful();

    $embeddedIds = ListingDocument::whereNotNull('embedding')->orderBy('listing_id')->pluck('listing_id')->all();
    expect($calls)->toBe([3])
        ->and($embeddedIds)->toBe(ListingDocument::orderBy('listing_id')->limit(3)->pluck('listing_id')->all());

    $this->artisan('listings:embed', ['--limit' => 3])->assertSuccessful();
    expect($calls)->toBe([3]);
});

it('AC-4 fresh clears the vectors in scope and embeds them again', function (): void {
    describedListings(4);
    $calls = [];
    fakeEmbeddingsCounting($calls);

    $this->artisan('listings:embed')->assertSuccessful();
    $this->artisan('listings:embed', ['--fresh' => true, '--limit' => 2])->assertSuccessful();

    expect($calls)->toBe([4, 2])
        ->and(embeddedCount())->toBe(4);

    $this->artisan('listings:embed', ['--fresh' => true])->assertSuccessful();

    expect($calls)->toBe([4, 2, 4]);
});

it('AC-2 wait writes the summary to --output with the documented keys', function (): void {
    describedListings(5);
    $calls = [];
    fakeEmbeddingsCounting($calls);
    $path = summaryPath();

    $this->artisan('listings:embed', ['--batch' => 2, '--wait' => true, '--output' => $path])->assertSuccessful();

    $summary = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    File::delete($path);

    expect(array_keys($summary))->toBe([
        'model', 'provider', 'ollama_version', 'ollama_num_parallel', 'batch', 'limit', 'listings_embedded', 'model_calls', 'failed_batches',
        'started_at', 'finished_at', 'seconds', 'embeddings_per_second', 'table_bytes', 'index_bytes', 'total_bytes',
    ])
        ->and($summary['model'])->toBe('bge-m3')
        ->and($summary['provider'])->toBe('ollama')
        ->and($summary['batch'])->toBe(2)
        ->and($summary['limit'])->toBeNull()
        ->and($summary['listings_embedded'])->toBe(5)
        ->and($summary['model_calls'])->toBe(3)
        ->and($summary['failed_batches'])->toBe(0)
        ->and($summary['table_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and($summary['index_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and($summary['total_bytes'])->toBeGreaterThanOrEqual($summary['table_bytes']);
});

it('AC-8 the summary records the Ollama version, num_parallel and fractional seconds', function (): void {
    Http::fake(['*/api/version' => Http::response(['version' => '0.40.13'])]);
    config()->set('ai.providers.ollama.num_parallel', 4);
    describedListings(5);
    $calls = [];
    fakeEmbeddingsCounting($calls);
    $path = summaryPath();

    $this->artisan('listings:embed', ['--batch' => 2, '--wait' => true, '--output' => $path])->assertSuccessful();

    $summary = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    File::delete($path);

    expect($summary['ollama_version'])->toBe('0.40.13')
        ->and($summary['ollama_num_parallel'])->toBe(4)
        ->and($summary['seconds'])->toBeFloat()->toBeGreaterThan(0.0)->toBeLessThan(30.0)
        ->and(fmod($summary['seconds'], 1.0))->not->toBe(0.0);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/version'));
});

it('AC-8 the version and num_parallel are null when Ollama is unreachable or unset', function (): void {
    Http::fake(['*/api/version' => Http::response('down', 500)]);
    describedListings(2);
    $calls = [];
    fakeEmbeddingsCounting($calls);
    $path = summaryPath();

    $this->artisan('listings:embed', ['--wait' => true, '--output' => $path])->assertSuccessful();

    $summary = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    File::delete($path);

    expect($summary['ollama_version'])->toBeNull()
        ->and($summary['ollama_num_parallel'])->toBeNull();
});

it('AC-2 without --wait no summary is written', function (): void {
    describedListings(2);
    $calls = [];
    fakeEmbeddingsCounting($calls);
    $path = summaryPath();

    $this->artisan('listings:embed', ['--output' => $path])->assertSuccessful();

    expect(File::exists($path))->toBeFalse();
});

it('AC-2 summary-only writes the summary of the last run without calling the model', function (): void {
    describedListings(3);
    $calls = [];
    fakeEmbeddingsCounting($calls);
    $path = summaryPath();

    $this->artisan('listings:embed', ['--limit' => 2])->assertSuccessful();
    $this->artisan('listings:embed', ['--summary-only' => true, '--output' => $path])->assertSuccessful();

    $summary = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    File::delete($path);

    expect($calls)->toBe([2])
        ->and($summary['limit'])->toBe(2)
        ->and($summary['listings_embedded'])->toBe(2)
        ->and($summary['model_calls'])->toBe(1);
});

it('AC-2 summary-only without a previous run fails', function (): void {
    $this->artisan('listings:embed', ['--summary-only' => true])
        ->expectsOutputToContain('No embedding run')
        ->assertFailed();
});

it('AC-2 a run refuses while jobs of a previous run are queued', function (): void {
    describedListings(2);
    $calls = [];
    fakeEmbeddingsCounting($calls);
    // The guard asks the configured queue for its size, so use the database queue here.
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert(['queue' => 'embeddings', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

    $this->artisan('listings:embed')
        ->expectsOutputToContain('still queued')
        ->assertFailed();

    expect($calls)->toBe([]);
});

it('AC-2 rejects a batch or limit that is not a positive number', function (string $option, string $value): void {
    $this->artisan('listings:embed', [$option => $value])->assertExitCode(2);
})->with([['--batch', '0'], ['--batch', 'abc'], ['--limit', '-1']]);

it('AC-2 tells how many listings have no description yet', function (): void {
    Listing::factory()->count(2)->for(Dealer::factory())->create();
    $calls = [];
    fakeEmbeddingsCounting($calls);

    $this->artisan('listings:embed')
        ->expectsOutputToContain('2 listings have no description yet')
        ->assertSuccessful();

    expect($calls)->toBe([]);
});
