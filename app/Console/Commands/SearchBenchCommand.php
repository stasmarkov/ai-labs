<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SemanticBenchService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('listings:search-bench
    {--queries=bench/semantic-queries.txt : File with one query per line}
    {--repeat=5 : Times every query is run for the latency samples}
    {--index : Drop and rebuild the HNSW index first and time the build}
    {--probe=family car with a big boot : Query whose body types are counted}
    {--output= : JSON file path}')]
#[Description('Measure semantic search latency, HNSW recall against an exact scan, and optionally the index build time')]
final class SearchBenchCommand extends Command
{
    public function handle(SemanticBenchService $bench): int
    {
        $path = $this->absolute((string) $this->option('queries'));

        if (! File::isFile($path)) {
            $this->error("Query file not found: {$path}");

            return self::FAILURE;
        }

        $queries = array_values(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []), fn (string $line): bool => $line !== ''));
        $repeat = filter_var($this->option('repeat'), FILTER_VALIDATE_INT);

        if ($queries === [] || $repeat === false || $repeat < 1) {
            $this->error('Need at least one query and a positive --repeat.');

            return self::INVALID;
        }

        if ($bench->embeddedCount() === 0) {
            $this->error('No embedded listings; run listings:embed first.');

            return self::FAILURE;
        }

        $report = $bench->run($queries, $repeat, (bool) $this->option('index'), (string) $this->option('probe'))->toArray();

        $output = $this->absolute((string) ($this->option('output') ?: sprintf('bench/results/%s-embeddings/search-bench.json', now()->format('Y-m-d'))));
        File::ensureDirectoryExists(dirname($output));
        File::put($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->info("recall@10 {$report['recall_at_10']}, embed p50 {$report['embed_ms']['p50']} ms, db p50 {$report['db_ms']['p50']} ms, db p95 {$report['db_ms']['p95']} ms");
        $this->line("Report: {$output}");

        return self::SUCCESS;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
