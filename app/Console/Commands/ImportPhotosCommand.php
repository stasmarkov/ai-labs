<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\PhotoImportException;
use App\Services\PhotoImportService;
use App\Support\StanfordCarsDataset;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('photos:import
    {--limit=600 : Most photos to store}
    {--per-model=12 : Most photos for one catalog model}
    {--fresh : Delete the stored Stanford Cars photos first}
    {--dataset= : Dataset folder (default storage/app/datasets/stanford-cars)}')]
#[Description('Match Stanford Cars photos to listings by make, model and year and store them on the public disk')]
final class ImportPhotosCommand extends Command
{
    public function handle(PhotoImportService $service): int
    {
        $limit = $this->positiveOption('limit');
        $perModel = $this->positiveOption('per-model');

        if ($limit === null || $perModel === null) {
            $this->error('--limit and --per-model must be positive whole numbers.');

            return self::INVALID;
        }

        $directory = (string) ($this->option('dataset') ?: storage_path('app/datasets/stanford-cars'));

        try {
            $report = $service->import(new StanfordCarsDataset($directory), $limit, $perModel, (bool) $this->option('fresh'));
        } catch (PhotoImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Classes matched: {$report->classesMatched}");
        $this->line("Classes skipped: {$report->classesSkipped}");
        $this->info("Photos stored: {$report->photosStored}");
        $this->line("Already imported: {$report->alreadyImported}");
        $this->line("Skipped, model cap reached: {$report->skippedOverModelCap}");
        $this->line("Skipped, no free listing: {$report->skippedNoListing}");
        $this->line("Unreadable or missing files: {$report->unreadableFiles}");
        $this->info("Listings with a photo: {$report->listingsWithPhoto}");

        if (! is_link(public_path('storage'))) {
            $this->warn('The photos are stored but not served yet: run ddev artisan storage:link (ddev setup does it).');
        }

        return self::SUCCESS;
    }

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }
}
