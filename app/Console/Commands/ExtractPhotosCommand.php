<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\AiProviders;
use App\Ai\OllamaVisionModels;
use App\Ai\PhotoVisionAgent;
use App\DTOs\PhotoExtractionRequest;
use App\Exceptions\ProviderNotConfiguredException;
use App\Jobs\ExtractPhotoJob;
use App\Services\PhotoExtractionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('photos:extract
    {--limit= : Only the first n pending photos}
    {--model= : A vision model of the photo-vision provider (default from config)}
    {--fresh : Delete the extractions of this model and prompt version first}')]
#[Description('Queue a vision extraction job for each photo that has none for the model and prompt version')]
final class ExtractPhotosCommand extends Command
{
    public function handle(PhotoExtractionService $service, AiProviders $providers, OllamaVisionModels $visionModels): int
    {
        $limit = $this->option('limit');

        if ($limit !== null && (filter_var($limit, FILTER_VALIDATE_INT) === false || (int) $limit < 1)) {
            $this->error('--limit must be a positive whole number.');

            return self::INVALID;
        }

        $configured = $providers->configured('photo-vision');

        try {
            $providers->forFeature('photo-vision');
        } catch (ProviderNotConfiguredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $model = filled($this->option('model')) ? (string) $this->option('model') : $configured->model;

        if ($model !== $configured->model && $configured->driver === 'ollama' && ! $visionModels->has($model)) {
            $this->error("{$model} is not a vision model on this Ollama.");

            return self::FAILURE;
        }

        $request = new PhotoExtractionRequest($configured->provider, $model, (new PhotoVisionAgent($model))->promptVersion());
        $pending = $service->pending($request, $limit === null ? null : (int) $limit, (bool) $this->option('fresh'));
        $skipped = $service->totalPhotos() - count($pending);

        if ($pending === []) {
            $this->info("No photos to extract; {$skipped} already have an extraction for {$model} ({$request->promptVersion}).");

            return self::SUCCESS;
        }

        $extractionIds = [];

        $this->withProgressBar($pending, function (int $photoId) use ($service, $request, &$extractionIds): void {
            $extractionId = $service->queue($photoId, $request);

            if ($extractionId !== null) {
                $extractionIds[] = $extractionId;
            }
        });
        $this->newLine(2);

        $counts = $service->statusCounts($extractionIds);

        $this->info('Queued '.count($extractionIds).' photos on '.ExtractPhotoJob::QUEUE." for {$model} ({$request->promptVersion}); {$skipped} skipped.");
        $this->line("Completed {$counts->completed}, failed {$counts->failed}, still queued {$counts->queued}, running {$counts->running}.");

        if ($counts->queued > 0) {
            $this->line('Run a worker: ddev artisan queue:work --queue='.ExtractPhotoJob::QUEUE);
        }

        return self::SUCCESS;
    }
}
