<?php

declare(strict_types=1);

use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\ListingFacts;
use App\DTOs\PhotoEmbedding;
use App\Exceptions\RagEvalSetException;
use App\Models\AiRun;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use App\Services\DocsCorpus;
use App\Services\ListingDocumentGenerator;
use App\Services\PhotoSearchEvalScorer;
use App\Services\PhotoSearchEvalSet;
use App\Services\RagEvalSet;
use Carbon\CarbonImmutable;
use Database\Seeders\Catalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const SEARCH_EVAL_LABEL = 'photo-encoder/openai/clip-vit-base-patch32';

function searchEvalRoot(): string
{
    return storage_path('framework/testing/photo-search-eval');
}

/**
 * @return list<float>
 */
function searchEvalVector(float $score): array
{
    $vector = array_fill(0, 512, 0.0);
    $vector[0] = $score;
    $vector[1] = sqrt(1 - $score * $score);

    return $vector;
}

function searchEvalPhoto(string $make, string $model, float $score): ListingPhoto
{
    $photo = ListingPhoto::factory()->create(['listing_id' => Listing::factory()->create(['make' => $make, 'model' => $model])->id]);
    app(ListingPhotoRepositoryInterface::class)->storeEmbeddings([new PhotoEmbedding($photo->id, searchEvalVector($score))], SEARCH_EVAL_LABEL, CarbonImmutable::now());

    return $photo;
}

/**
 * @param  list<string>  $colours
 */
function searchEvalExtraction(ListingPhoto $photo, array $colours, string $status = PhotoExtraction::STATUS_COMPLETED, string $model = 'gemma4:12b'): void
{
    PhotoExtraction::factory()->create([
        'photo_id' => $photo->id,
        'model' => $model,
        'status' => $status,
        'data' => ['caption' => 'A car.', 'dominant_colours' => $colours],
    ]);
}

/**
 * @return array<string, mixed>
 */
function searchEvalRun(): array
{
    return json_decode((string) File::get(searchEvalRoot().'/photo-search/2026-11-11/run.json'), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    File::ensureDirectoryExists(searchEvalRoot());
    config([
        'ai.docs.eval_results_root' => searchEvalRoot(),
        'ai.features.embeddings-photos.provider' => 'photo-encoder',
        'ai.features.embeddings-photos.model' => null,
        'ai.providers.photo-encoder.url' => 'http://encoder.test:7997',
        'ai.photo_search.min_score' => 0.2,
    ]);
    Carbon::setTestNow('2026-11-11 10:00:00');
    Http::preventStrayRequests();
    Http::fake(['encoder.test:7997/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => searchEvalVector(1.0)]]])]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    File::deleteDirectory(searchEvalRoot());
});

it('AC-7 computes precision, MRR, coverage and the objective on fixed relevance flags', function (): void {
    $report = (new PhotoSearchEvalScorer)->score([[true, false], [false, false], [], [false, true, true]]);

    // Precision per query: 1/2, 0, 0 (nothing returned), 2/3. Reciprocal rank: 1, 0, 0, 1/2.
    expect($report)->toBe([
        'queries' => 4,
        'answered' => 3,
        'coverage' => 0.75,
        'precision' => 0.2917,
        'mrr' => 0.375,
        'hit_rate' => 0.5,
        'objective' => 0.2187,
    ])
        ->and((new PhotoSearchEvalScorer)->score([])['objective'])->toBe(0.0);
});

it('AC-7 chooses the threshold with the best objective and the lower one on a tie', function (): void {
    $scorer = new PhotoSearchEvalScorer;

    expect($scorer->choose([
        ['threshold' => 0.15, 'objective' => 0.3],
        ['threshold' => 0.2, 'objective' => 0.5],
        ['threshold' => 0.25, 'objective' => 0.5],
        ['threshold' => 0.3, 'objective' => 0.1],
    ]))->toBe(0.2)
        ->and($scorer->choose([]))->toBeNull()
        ->and($scorer->choose([['threshold' => 0.15, 'objective' => 0.0], ['threshold' => 0.2, 'objective' => 0.0]]))->toBeNull()
        ->and(PhotoSearchEvalScorer::THRESHOLDS)->toBe([0.15, 0.175, 0.2, 0.225, 0.25, 0.275, 0.3, 0.325, 0.35]);
});

it('AC-7 writes both prompt variants, the curve, the pool and both latencies', function (): void {
    $bmw = searchEvalPhoto('BMW', '3 Series', 0.30);
    $audi = searchEvalPhoto('Audi', 'A4', 0.25);
    searchEvalPhoto('Ford', 'Fiesta', 0.10);
    searchEvalExtraction($bmw, ['black']);
    searchEvalExtraction($audi, ['white'], PhotoExtraction::STATUS_FAILED);

    $this->artisan('photos:search-eval')->assertExitCode(0);

    $run = searchEvalRun();
    $template = $run['variants']['template'];
    $bare = $run['variants']['bare'];
    $atPointThree = collect($template['curve'])->firstWhere('threshold', 0.3);

    expect($run['metadata'])->toMatchArray(['label' => SEARCH_EVAL_LABEL, 'queries' => 30, 'k' => 8])
        ->and($run['pool'])->toBe(['embedded_photos' => 3, 'colour_photos' => 1])
        ->and($template['prompt'])->toBe('photo-search.v1')
        ->and($bare['prompt'])->toBe('photo-search.v0')
        ->and(array_column($template['curve'], 'threshold'))->toBe(PhotoSearchEvalScorer::THRESHOLDS)
        ->and(array_column($bare['curve'], 'threshold'))->toBe(PhotoSearchEvalScorer::THRESHOLDS)
        ->and($bare['compared_at_min_score'])->toBe($template['chosen_min_score'])
        ->and($run['chosen_min_score'])->toBe($template['chosen_min_score'])
        ->and($template['metrics'])->toHaveKeys(['precision', 'mrr', 'coverage', 'hit_rate', 'objective'])
        ->and($bare['metrics'])->toBe($template['metrics'])
        ->and($run['latency']['uncached']['samples'])->toBe(30)
        ->and($run['latency']['cached']['samples'])->toBe(30)
        ->and($run['latency']['cached'])->toHaveKeys(['p50_ms', 'p95_ms']);

    // From 0.275 only the BMW (saloon, black) is returned, to every query. It is relevant to the BMW query, the BMW 3 Series
    // query, the saloon query and the black saloon query: 4 of 30. Colour queries see the one extracted photo only.
    expect($atPointThree)->toMatchArray(['answered' => 30, 'coverage' => 1.0, 'precision' => 0.1333, 'hit_rate' => 0.1333]);

    $objectives = array_column($template['curve'], 'objective');
    expect($template['chosen_min_score'])->toBe($template['curve'][array_search(max($objectives), $objectives, true)]['threshold']);
});

it('AC-7 grades a colour query over the photos with a completed extraction only', function (): void {
    $bmw = searchEvalPhoto('BMW', '3 Series', 0.30);
    searchEvalPhoto('Audi', 'A4', 0.28);
    searchEvalExtraction($bmw, ['black']);
    searchEvalExtraction($bmw, ['red'], model: 'another-model');

    $this->artisan('photos:search-eval', ['--k' => 2])->assertExitCode(0);

    $perQuery = array_column(searchEvalRun()['variants']['template']['per_query'], null, 'id');

    expect($perQuery['ps-23'])->toBe(['id' => 'ps-23', 'returned' => 1, 'relevant' => 1])
        ->and($perQuery['ps-21'])->toBe(['id' => 'ps-21', 'returned' => 1, 'relevant' => 0])
        ->and($perQuery['ps-14']['returned'])->toBe(2)
        ->and($perQuery['ps-14']['relevant'])->toBe(2)
        ->and($perQuery['ps-01'])->toBe(['id' => 'ps-01', 'returned' => 2, 'relevant' => 1]);
});

it('AC-7 treats grey and silver as one colour', function (): void {
    $audi = searchEvalPhoto('Audi', 'A6', 0.30);
    searchEvalExtraction($audi, ['grey']);

    $this->artisan('photos:search-eval')->assertExitCode(0);

    expect(array_column(searchEvalRun()['variants']['template']['per_query'], null, 'id')['ps-24'])->toBe(['id' => 'ps-24', 'returned' => 1, 'relevant' => 1]);
});

it('AC-7 searches the templated text and the bare query, writes eval run rows and leaves the config as it was', function (): void {
    searchEvalPhoto('BMW', '3 Series', 0.30);

    $this->artisan('photos:search-eval')->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request['input'] === ['a photo of a BMW parked outside a house']);
    Http::assertSent(fn (Request $request): bool => $request['input'] === ['a BMW parked outside a house']);
    Http::assertSentCount(90);

    expect(AiRun::query()->where('agent', 'PhotoTextSearch')->where('origin', 'eval')->count())->toBe(90)
        ->and(AiRun::query()->where('prompt_version', 'photo-search@v0')->count())->toBe(30)
        ->and(config('ai.photo_search.min_score'))->toBe(0.2)
        ->and(config('ai.photo_search.prompt'))->toBe('photo-search.v1');
});

it('AC-7 exits 1 when no photo is embedded with the current label', function (): void {
    ListingPhoto::factory()->create();

    $this->artisan('photos:search-eval')->assertExitCode(1);

    expect(File::exists(searchEvalRoot().'/photo-search'))->toBeFalse();
    Http::assertNothingSent();
});

it('AC-7 rejects a bad --k and refuses to overwrite a result without --force', function (): void {
    searchEvalPhoto('BMW', '3 Series', 0.30);

    $this->artisan('photos:search-eval', ['--k' => '0'])->assertExitCode(2);
    $this->artisan('photos:search-eval')->assertExitCode(0);
    $this->artisan('photos:search-eval')->assertExitCode(2);
    $this->artisan('photos:search-eval', ['--force' => true])->assertExitCode(0);
});

it('AC-7 the query set has 30 valid queries in the planned mix', function (): void {
    $queries = (new PhotoSearchEvalSet)->load();
    $byShape = collect($queries)->countBy(fn ($query): string => match (true) {
        $query->usesColour() => 'colour',
        isset($query->rule['body_type']) => 'body',
        default => 'make',
    });

    expect($queries)->toHaveCount(30)
        ->and($byShape->all())->toBe(['make' => 12, 'body' => 8, 'colour' => 10])
        ->and(array_unique(array_map(fn ($query): string => $query->id, $queries)))->toHaveCount(30);
});

it('AC-7 rejects a query set with a rule outside the catalogue', function (string $line): void {
    $path = searchEvalRoot().'/queries.jsonl';
    File::put($path, $line."\n");

    expect(fn () => (new PhotoSearchEvalSet)->load($path))->toThrow(RagEvalSetException::class);
})->with([
    'unknown make' => ['{"id":"ps-99","query":"a Lada outside","rule":{"make":"Lada"}}'],
    'unknown field' => ['{"id":"ps-99","query":"a fast car","rule":{"speed":"fast"}}'],
    'empty rule' => ['{"id":"ps-99","query":"a fast car","rule":{}}'],
    'unknown colour' => ['{"id":"ps-99","query":"a pink car","rule":{"colour":"pink"}}'],
    'short query' => ['{"id":"ps-99","query":"a","rule":{"make":"BMW"}}'],
    'bad id' => ['{"id":"X","query":"a BMW outside","rule":{"make":"BMW"}}'],
]);

/**
 * Every text the app indexes or stores about photos and listings: the corpus files, the generated listing
 * descriptions across the catalogue, and the stored descriptions, doc chunks and extraction captions.
 *
 * @return list<string> normalised texts
 */
function searchEvalIndexedTexts(): array
{
    $corpus = new DocsCorpus;
    $texts = [];

    foreach ($corpus->sources() as $source) {
        if (! $corpus->isAvailable($source)) {
            continue;
        }

        foreach ($corpus->files($source) as $file) {
            $texts[] = $corpus->read($file);
        }
    }

    $generator = new ListingDocumentGenerator;
    $id = 0;

    foreach (Catalog::MODELS_BY_MAKE as $make => $models) {
        foreach ($models as $model) {
            foreach ([2012, 2018, 2024] as $year) {
                $texts[] = $generator->generate(new ListingFacts(++$id, $year, $make, $model, 'petrol', 40_000))->description;
            }
        }
    }

    foreach (['listing_documents' => 'description', 'docs_chunks' => 'body'] as $table => $column) {
        $texts = [...$texts, ...DB::table($table)->pluck($column)->all()];
    }

    foreach (DB::table('photo_extractions')->pluck('data') as $data) {
        $texts[] = (string) (json_decode((string) $data, true)['caption'] ?? '');
    }

    return array_map(RagEvalSet::normalizeText(...), $texts);
}

/**
 * @param  list<string>  $texts
 * @return list<string> the ids of the queries that occur in a text
 */
function searchEvalContaminated(array $texts): array
{
    $found = [];

    foreach ((new PhotoSearchEvalSet)->load() as $query) {
        $needle = RagEvalSet::normalizeText($query->query);

        foreach ($texts as $text) {
            if (str_contains($text, $needle)) {
                $found[] = $query->id;

                break;
            }
        }
    }

    return $found;
}

it('AC-7 finds no eval query in any indexed text', function (): void {
    expect(searchEvalContaminated(searchEvalIndexedTexts()))->toBe([]);
});

it('AC-7 the contamination check sees a query planted in a caption, a description and a chunk', function (): void {
    $photo = ListingPhoto::factory()->create();
    searchEvalExtraction($photo, ['red']);
    PhotoExtraction::query()->update(['data' => ['caption' => 'Look: a Tesla electric car, parked.', 'dominant_colours' => ['red']]]);

    expect(searchEvalContaminated(searchEvalIndexedTexts()))->toBe(['ps-07'])
        ->and(searchEvalContaminated([RagEvalSet::normalizeText('Notes on an Audi A4 in profile today')]))->toBe(['ps-10']);
});

/**
 * @return array<string, mixed>
 */
function searchEvalRecordedRun(): array
{
    return json_decode((string) file_get_contents(base_path('bench/results/photo-search/2026-11-11/run.json')), true, flags: JSON_THROW_ON_ERROR);
}

function searchEvalBenchmarksSection(): string
{
    $text = (string) file_get_contents(base_path('BENCHMARKS.md'));
    $start = strpos($text, '## Search car photos by text');
    $end = strpos($text, "\n## ", $start + 1);

    return substr($text, $start, $end === false ? null : $end - $start);
}

/**
 * @param  array<string, float|int>  $row
 */
function searchEvalCurveRow(array $row): string
{
    return sprintf('| %.3f | %.3f | %.3f | %.3f |', $row['threshold'], $row['coverage'], $row['precision'], $row['mrr']);
}

it('AC-7 BENCHMARKS.md presents the threshold curve of both prompt variants value for value', function (): void {
    $section = searchEvalBenchmarksSection();
    $run = searchEvalRecordedRun();

    foreach (['template', 'bare'] as $variant) {
        $heading = '#### Threshold curve: `'.$run['variants'][$variant]['prompt'].'`';
        $position = strpos($section, $heading);
        expect($position)->not->toBeFalse();

        $table = substr($section, $position);
        $next = strpos($table, "\n\n", strlen($heading) + 2 + 200);
        $table = substr($table, 0, $next === false ? null : $next);

        $rows = array_values(array_filter(explode("\n", $table), fn (string $line): bool => preg_match('/^\| \d\.\d{3} \|/', $line) === 1 || preg_match('/^\| \*\*\d\.\d{3}\*\* \|/', $line) === 1));
        $rows = array_map(fn (string $line): string => str_replace('**', '', $line), $rows);

        expect($rows)->toBe(array_map('searchEvalCurveRow', $run['variants'][$variant]['curve']));
    }
});

it('AC-7 BENCHMARKS.md describes precision as the per-query mean with empty queries counted as 0', function (): void {
    $section = searchEvalBenchmarksSection();

    expect($section)->not->toContain('micro average')
        ->and($section)->toContain('mean over all queries of the share of returned results that are relevant')
        ->and($section)->toContain('counts as 0');
});

it('AC-7 refuses a --k above the number of results a search returns', function (): void {
    $this->artisan('photos:search-eval', ['--k' => 20])->expectsOutputToContain('from 1 to 12')->assertExitCode(2);
});
