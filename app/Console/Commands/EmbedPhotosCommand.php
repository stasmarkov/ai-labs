<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTOs\PhotoEmbedRun;
use App\Exceptions\EmbeddingDimensionsException;
use App\Exceptions\PhotoEncoderException;
use App\Jobs\EmbedPhotosBatchJob;
use App\Services\PhotoEmbeddingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('photos:embed
    {--limit= : Only the first n photos by id}
    {--fresh : Clear the vectors in scope first}
    {--wait : Wait for the queue to drain, then print the summary}')]
#[Description('Embed listing photos with the image encoder in queued batches')]
final class EmbedPhotosCommand extends Command
{
    private const WAIT_LIMIT_SECONDS = 1800;

    private const STALL_SECONDS = 60;

    private const POLL_SECONDS = 2;

    public function handle(PhotoEmbeddingService $service): int
    {
        $limit = $this->option('limit');

        if ($limit !== null && (filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < 1)) {
            $this->error('--limit must be a positive whole number.');

            return self::INVALID;
        }

        try {
            $service->assertReady();
        } catch (EmbeddingDimensionsException|PhotoEncoderException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $run = $service->dispatch($limit === null ? null : (int) $limit, (bool) $this->option('fresh'));

        $this->info("Queued {$run->jobs} jobs for {$run->photos} photos on ".EmbedPhotosBatchJob::QUEUE." ({$service->label()}).");

        if (! $this->option('wait')) {
            return self::SUCCESS;
        }

        if (! $this->waitFor($service, $run)) {
            return self::FAILURE;
        }

        $progress = $service->progress($run);
        $seconds = max(0.001, $run->startedAt->diffInMilliseconds(CarbonImmutable::now()) / 1000);
        $embedded = $service->embeddedSince($run->startedAt);

        $this->info(sprintf('Embedded %d photos in %.1f s (%.1f/s).', $embedded, $seconds, $embedded / $seconds));

        if ($progress->missing > 0) {
            $this->warn("{$progress->missing} files missing, skipped.");
        }

        if ($progress->failed > 0) {
            $this->error("{$progress->failed} photos in failed batches; see the PhotoEncoder rows on /ai/runs.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function waitFor(PhotoEmbeddingService $service, PhotoEmbedRun $run): bool
    {
        if ($run->photos === 0) {
            return true;
        }

        $bar = null;
        $deadline = time() + self::WAIT_LIMIT_SECONDS;
        $lastProgressAt = time();
        $last = 0;
        $warned = false;

        while (($progress = $service->progress($run))->settled < $run->photos) {
            if (time() >= $deadline) {
                $this->newLine();
                $this->error('Timed out waiting for the photo embedding jobs.');

                return false;
            }

            $bar ??= $this->output->createProgressBar($run->photos);

            if ($progress->settled > $last) {
                $last = $progress->settled;
                $lastProgressAt = time();
                $bar->setProgress($last);
            } elseif (! $warned && time() - $lastProgressAt >= self::STALL_SECONDS) {
                $warned = true;
                $this->newLine();
                $this->warn('No progress; start a worker: ddev artisan queue:work --queue='.EmbedPhotosBatchJob::QUEUE);
            }

            sleep(self::POLL_SECONDS);
        }

        if ($bar !== null) {
            $bar->finish();
            $this->newLine(2);
        }

        return true;
    }
}
