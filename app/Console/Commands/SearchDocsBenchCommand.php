<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\DocsBenchQuery;
use App\DTOs\DocsBenchRow;
use App\Exceptions\DocsSearchUnavailableException;
use App\Services\DocsIndexReportService;
use App\Services\DocsSearchBenchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('docs:search-bench
    {--queries=bench/docs-queries.txt : Tab-separated type, query and expected files}
    {--index-seconds= : Wall-clock seconds docs:index took, recorded as given}
    {--sample=10 : Chunks per source for the token estimate check}
    {--output-dir= : Directory for index.json and search-bench.json}')]
#[Description('Record the docs index statistics and whether each benchmark query finds its expected file in every retrieval mode')]
final class SearchDocsBenchCommand extends Command
{
    public function handle(DocsSearchBenchService $bench, DocsIndexReportService $index): int
    {
        $path = $this->absolute((string) $this->option('queries'));

        if (! File::isFile($path)) {
            $this->error("Query file not found: {$path}");

            return self::FAILURE;
        }

        $queries = [];

        foreach (explode("\n", (string) File::get($path)) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            $query = DocsBenchQuery::fromLine(rtrim($line, "\r"));

            if ($query === null) {
                $this->error('Line '.($number + 1)." of {$path} is not: identifier|meaning, query, source:path[|source:path].");

                return self::INVALID;
            }

            $queries[] = $query;
        }

        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT);
        $seconds = $this->option('index-seconds');

        if ($queries === [] || $sample === false || $sample < 1 || ($seconds !== null && ! is_numeric($seconds))) {
            $this->error('Need at least one query, a positive --sample and a numeric --index-seconds.');

            return self::INVALID;
        }

        if ($index->embeddedCount() === 0) {
            $this->error('No embedded doc chunks; run docs:index and a queue worker first.');

            return self::FAILURE;
        }

        try {
            $search = $bench->run($queries);
        } catch (DocsSearchUnavailableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Type', 'Query', ...$search->modes],
            array_map(fn (DocsBenchRow $row): array => [
                $row->query->type,
                mb_substr($row->query->query, 0, 50),
                ...array_map(fn (string $mode): string => $row->passed($mode) ? 'top '.$row->rank($mode) : 'miss', $search->modes),
            ], $search->rows),
        );

        foreach ($search->summary() as $type => $counts) {
            $this->line("{$type}: ".implode(', ', array_map(fn (string $mode): string => "{$mode} {$counts[$mode]}/{$counts['total']}", $search->modes)));
        }

        $this->line('Hybrid thresholds (at least 4 of 5 for each type): '.($search->thresholdsMet() ? 'met' : 'NOT met'));

        $report = $index->run($seconds === null ? null : (float) $seconds, $sample)->toArray();
        $check = $report['token_check'];
        $this->line("Token estimate over {$check['chunks']} chunks: mean estimate/real {$check['mean_ratio']}, max {$check['max_ratio']}, mean absolute error {$check['mean_abs_error']} tokens");

        $directory = $this->absolute((string) ($this->option('output-dir') ?: sprintf('bench/results/%s-docs-index', now()->format('Y-m-d'))));
        File::ensureDirectoryExists($directory);
        File::put("{$directory}/search-bench.json", $this->json($search->toArray()));
        File::put("{$directory}/index.json", $this->json($report));
        $this->line("Reports: {$directory}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
