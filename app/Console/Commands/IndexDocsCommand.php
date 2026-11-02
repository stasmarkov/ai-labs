<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DocsIndexService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('docs:index {--source= : Only "repo" or "laravel" (default both)}')]
#[Description('Chunk the changed docs and queue embedding jobs for chunks without a current vector')]
final class IndexDocsCommand extends Command
{
    public function handle(DocsIndexService $service): int
    {
        $source = $this->option('source');

        if ($source !== null && ! in_array($source, $service->sources(), true)) {
            $this->error('--source must be one of: '.implode(', ', $service->sources()).'.');

            return self::INVALID;
        }

        $queued = $service->queuedJobs();

        if ($queued > 0) {
            $this->error("{$queued} embedding jobs from a previous run are still queued. Let a worker finish them first.");

            return self::FAILURE;
        }

        if ($source !== null && ! $service->isAvailable($source)) {
            $this->error("The {$source} docs folder is missing; run docs:fetch-laravel.");

            return self::FAILURE;
        }

        $run = $service->index($source === null ? $service->sources() : [$source]);

        foreach ($run->skippedSources as $skipped) {
            $this->warn("The {$skipped} docs folder is missing and was skipped; run docs:fetch-laravel.");
        }

        $this->info("Documents: {$run->documentsChanged} indexed, {$run->documentsUnchanged} unchanged, {$run->documentsRemoved} removed.");
        $this->info("Queued {$run->jobs} jobs for {$run->chunks} chunks (batch {$run->batch}).");

        return self::SUCCESS;
    }
}
