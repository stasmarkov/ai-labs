<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\EmbeddingRun;
use App\Exceptions\EmbeddingDimensionsException;
use App\Exceptions\ProviderNotConfiguredException;
use App\Services\EmbeddingSummaryWriter;
use App\Services\ListingEmbeddingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('listings:embed
    {--batch= : Descriptions per model call (default from config)}
    {--limit= : Only the first n listings by id}
    {--fresh : Clear the vectors in scope first}
    {--wait : Wait for the queue to drain, then write the summary}
    {--summary-only : Write the summary of the last run without dispatching}
    {--output= : Summary file path}
    {--timeout=7200 : Seconds to wait with --wait}')]
#[Description('Embed listing descriptions in queued batches and write a JSON summary')]
final class EmbedListingsCommand extends Command
{
    public function handle(ListingEmbeddingService $service, EmbeddingSummaryWriter $writer): int
    {
        $batch = $this->positiveOption('batch') ?? $service->defaultBatch();
        $limit = $this->positiveOption('limit');

        if (($this->option('batch') !== null && $this->positiveOption('batch') === null) || ($this->option('limit') !== null && $limit === null)) {
            $this->error('--batch and --limit must be positive whole numbers.');

            return self::INVALID;
        }

        try {
            $service->assertDimensions(ListingEmbeddingService::FEATURE);
        } catch (EmbeddingDimensionsException|ProviderNotConfiguredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('summary-only')) {
            $run = $service->lastRun();

            if ($run === null) {
                $this->error('No embedding run to summarise yet.');

                return self::FAILURE;
            }

            return $this->writeSummary($service, $writer, $run);
        }

        $queued = $service->queuedJobs();

        if ($queued > 0) {
            $this->error("{$queued} embedding jobs from a previous run are still queued. Let a worker finish them first.");

            return self::FAILURE;
        }

        $missing = $service->listingsWithoutDocument();

        if ($missing > 0) {
            $this->warn("{$missing} listings have no description yet; run listings:describe-all.");
        }

        $run = $service->dispatch($batch, $limit, (bool) $this->option('fresh'));

        $this->info("Queued {$run->jobs} jobs for {$run->listings} listings (batch {$run->batch}).");

        if (! $this->option('wait')) {
            return self::SUCCESS;
        }

        if (! $this->waitForQueue($service, (int) $this->option('timeout'))) {
            $this->error('Timed out waiting for the embedding queue to drain.');

            return self::FAILURE;
        }

        return $this->writeSummary($service, $writer, $run);
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    private function waitForQueue(ListingEmbeddingService $service, int $timeout): bool
    {
        $deadline = time() + $timeout;

        while ($service->queuedJobs() > 0) {
            if (time() >= $deadline) {
                return false;
            }

            sleep(1);
        }

        return true;
    }

    private function writeSummary(ListingEmbeddingService $service, EmbeddingSummaryWriter $writer, EmbeddingRun $run): int
    {
        $summary = $service->summarize($run);
        $path = $writer->write($summary, $this->option('output'));

        $data = $summary->toArray();
        $this->info("Embedded {$data['listings_embedded']} listings in {$data['seconds']} s ({$data['embeddings_per_second']}/s, {$data['model_calls']} model calls).");
        $this->line("Summary: {$path}");

        return self::SUCCESS;
    }
}
