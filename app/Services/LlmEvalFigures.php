<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Reads, never writes, the committed feature-eval runs and copies per model the JSON-valid rate and the main accuracy
 * figure from the latest run that contains the model.
 */
final readonly class LlmEvalFigures
{
    public const FEATURES = ['nl-search', 'assistant', 'moderation', 'photo-vision'];

    /**
     * @return array<string, array{json_valid: float|int|null, accuracy: float|int|null, accuracy_metric: string|null, source: string|null, date: string|null, reason?: string}>
     */
    public function forModel(string $model): array
    {
        $figures = [];

        foreach (self::FEATURES as $feature) {
            $figures[$feature] = $this->figure($feature, $model);
        }

        return $figures;
    }

    /**
     * @return array{json_valid: float|int|null, accuracy: float|int|null, accuracy_metric: string|null, source: string|null, date: string|null, reason?: string}
     */
    private function figure(string $feature, string $model): array
    {
        $root = rtrim((string) config('ai.docs.eval_results_root'), '/');
        $folders = collect(File::isDirectory("{$root}/{$feature}") ? File::directories("{$root}/{$feature}") : [])->map(basename(...))->sortDesc()->values();

        foreach ($folders as $folder) {
            $path = "{$root}/{$feature}/{$folder}/run.json";

            if (! File::isFile($path)) {
                continue;
            }

            $data = json_decode((string) File::get($path), true);
            $report = is_array($data) ? $this->report($data, $model) : null;

            if ($report === null) {
                continue;
            }

            [$validity, $accuracy, $metric] = $this->extract($feature, $report);

            $figure = [
                'json_valid' => $validity,
                'accuracy' => $accuracy,
                'accuracy_metric' => $metric,
                'source' => "bench/results/{$feature}/{$folder}/run.json",
                'date' => substr($folder, 0, 10),
            ];

            $missing = array_keys(array_filter(['json_valid' => $validity, 'accuracy' => $accuracy], static fn (mixed $value): bool => $value === null));

            if ($missing !== []) {
                $figure['reason'] = $feature === 'photo-vision' && in_array('accuracy', $missing, true) && ! in_array('json_valid', $missing, true)
                    ? 'the vision eval reports validity only for this model'
                    : 'the run has no '.implode(' or ', $missing).' figure for this model';
            }

            return $figure;
        }

        return [
            'json_valid' => null,
            'accuracy' => null,
            'accuracy_metric' => null,
            'source' => null,
            'date' => null,
            'reason' => "no committed {$feature} run contains {$model}",
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function report(array $data, string $model): ?array
    {
        foreach ((array) ($data['models'] ?? []) as $report) {
            if (is_array($report) && ($report['model'] ?? null) === $model) {
                return $report;
            }
        }

        $summary = $data['summary'][$model] ?? null;

        return is_array($summary) ? $summary : null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{0: float|int|null, 1: float|int|null, 2: string|null}
     */
    private function extract(string $feature, array $report): array
    {
        return match ($feature) {
            'nl-search' => [$this->nlSearchValidity($report), $report['exact_match']['rate'] ?? null, 'exact_match'],
            'assistant' => [$report['argument_validity_first_try']['rate'] ?? null, $report['filter_accuracy']['rate'] ?? null, 'filter_accuracy'],
            'moderation' => [$report['json_valid_first_try']['rate'] ?? null, $report['best_f1']['accuracy'] ?? null, 'accuracy_at_best_f1'],
            default => [$report['valid_first_attempt']['rate'] ?? null, $report['body_type_agreement']['rate'] ?? null, 'body_type_agreement'],
        };
    }

    /**
     * The share of queries that came back as a valid filter: failed calls and refusals are not valid answers, and neither
     * are the ones the validator rejected with a 422.
     *
     * @param  array<string, mixed>  $report
     */
    private function nlSearchValidity(array $report): ?float
    {
        $queries = $report['queries'] ?? null;
        $rejected = $report['validation_422']['count'] ?? null;

        if (! is_numeric($queries) || $queries <= 0 || ! is_numeric($rejected) || ! is_numeric($report['failed'] ?? null) || ! is_numeric($report['refused'] ?? null)) {
            return null;
        }

        return round(($queries - $rejected - $report['failed'] - $report['refused']) / $queries, 4);
    }
}
