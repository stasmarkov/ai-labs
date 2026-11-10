<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\ListingFilterAgent;
use App\Contracts\RagEvalRepositoryInterface;
use App\DTOs\NlEvalOutcome;
use App\DTOs\NlEvalQuery;
use App\DTOs\NlSearchQuery;
use App\DTOs\RagRunTiming;
use App\Exceptions\NlSearchInvalidException;
use App\Exceptions\NlSearchRefusedException;
use App\Exceptions\NlSearchUnavailableException;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\RagEvalOptionException;
use App\Exceptions\RagEvalResultException;
use App\Exceptions\RagEvalSetException;
use App\Services\NlEvalScorer;
use App\Services\NlEvalSet;
use App\Services\NlSearchService;
use App\Services\RagEvalResultWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use JsonException;

#[Signature('nl:eval
    {--models= : Comma-separated models (default: the configured text model)}
    {--queries= : Eval set file (default: bench/nl-search/queries.v1.jsonl)}
    {--label= : Name of the run (default: the UTC time HHMMSS)}
    {--output-dir= : Directory for the result file, replacing bench/results/nl-search/<UTC date>}
    {--force : Overwrite an existing result file}')]
#[Description('Measure the natural-language search: exact filter match, per-field accuracy, unsupported recall, 422 rate and latency per model')]
final class NlEvalCommand extends Command
{
    private const EMPTY = '—';

    public function handle(NlEvalSet $set, NlSearchService $search, NlEvalScorer $scorer, RagEvalRepositoryInterface $runs, RagEvalResultWriter $writer): int
    {
        $startedAt = Date::now('UTC');
        $label = trim((string) $this->option('label')) ?: $startedAt->format('His');

        try {
            $models = $this->models();

            if (preg_match('/\A[A-Za-z0-9._-]{1,60}\z/', $label) !== 1 || in_array($label, ['.', '..'], true)) {
                throw RagEvalOptionException::badLabel($label);
            }

            $directory = $writer->contain($this->directory($startedAt->format('Y-m-d')));
        } catch (RagEvalOptionException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $force = (bool) $this->option('force');
        $file = "{$label}.json";

        try {
            $writer->assertWritableFile($directory, $file, $force);
            $queries = $set->load($this->option('queries') ? $this->absolute((string) $this->option('queries')) : null);
        } catch (RagEvalResultException|RagEvalSetException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $reports = [];
        $results = [];

        try {
            foreach ($models as $model) {
                $this->line("Searching with {$model}: ".count($queries).' queries');
                $outcomes = array_map(fn (NlEvalQuery $query): NlEvalOutcome => $this->search($search, $query, $model), $queries);
                $ids = array_values(array_filter(array_map(
                    fn (NlEvalOutcome $outcome): ?string => $outcome->hasModelOutput() ? $outcome->invocationId : null,
                    $outcomes,
                )));
                $reports[] = $scorer->score($model, $queries, $outcomes, array_map(fn (RagRunTiming $timing): int => $timing->latencyMs, $runs->timings($ids)));

                foreach ($queries as $index => $query) {
                    $results[] = $this->resultRow($scorer, $model, $query, $outcomes[$index]);
                }
            }
        } catch (ProviderNotConfiguredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Model', 'Exact', 'Unsupported recall', '422', 'Refused', 'Failed', 'Median ms', 'p95 ms'],
            array_map(fn (array $report): array => [
                $report['model'],
                number_format($report['exact_match']['rate'], 3),
                "{$report['unsupported_recall']['caught']}/{$report['unsupported_recall']['expected']}",
                "{$report['validation_422']['count']}/{$report['queries']}",
                $report['refused'],
                $report['failed'],
                $report['latency_ms']['median'] ?? self::EMPTY,
                $report['latency_ms']['p95'] ?? self::EMPTY,
            ], $reports),
        );

        $data = [
            'metadata' => [
                'label' => $label,
                'generated_at' => $startedAt->toIso8601String(),
                'eval_set_version' => NlEvalSet::VERSION,
                'queries' => count($queries),
                'models' => $models,
                'provider' => (new ListingFilterAgent)->providerName(),
                'prompt_version' => (new ListingFilterAgent)->promptVersion(),
            ],
            'models' => $reports,
            'results' => $results,
        ];

        try {
            $writer->writeFile($directory, $file, $data, $force);
        } catch (RagEvalResultException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Results: {$directory}/{$file}");

        return self::SUCCESS;
    }

    private function search(NlSearchService $search, NlEvalQuery $query, string $model): NlEvalOutcome
    {
        try {
            $result = $search->search(new NlSearchQuery($query->query, $model, 1));

            return new NlEvalOutcome(NlEvalOutcome::COMPLETED, $result->extracted, $result->unsupported, $result->invocationId);
        } catch (NlSearchInvalidException $e) {
            return new NlEvalOutcome(NlEvalOutcome::INVALID, $e->extracted, $e->unsupported, $e->invocationId);
        } catch (NlSearchRefusedException $e) {
            return new NlEvalOutcome(NlEvalOutcome::REFUSED, invocationId: $e->invocationId);
        } catch (NlSearchUnavailableException) {
            return new NlEvalOutcome(NlEvalOutcome::FAILED);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resultRow(NlEvalScorer $scorer, string $model, NlEvalQuery $query, NlEvalOutcome $outcome): array
    {
        $matches = $scorer->fieldMatches($query, $outcome);

        return [
            'id' => $query->id,
            'model' => $model,
            'status' => $outcome->status,
            'exact' => ! in_array(false, $matches, true),
            'wrong_fields' => array_keys(array_filter($matches, fn (bool $hit): bool => ! $hit)),
            'extracted' => $outcome->extracted,
            'unsupported' => $outcome->unsupported,
            'invocation_id' => $outcome->invocationId,
        ];
    }

    /**
     * @return list<string>
     *
     * @throws RagEvalOptionException
     */
    private function models(): array
    {
        $models = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('models'))))));

        if ($models === []) {
            return [(new ListingFilterAgent)->modelName()];
        }

        $allowed = NlSearchService::allowedModels();

        foreach ($models as $model) {
            if (! in_array($model, $allowed, true)) {
                throw RagEvalOptionException::unknownModel($model, $allowed);
            }
        }

        return $models;
    }

    private function directory(string $date): string
    {
        return $this->absolute((string) $this->option('output-dir') ?: "bench/results/nl-search/{$date}");
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
