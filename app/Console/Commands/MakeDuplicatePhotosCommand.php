<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\PhotoImportException;
use App\Services\PhotoDuplicateGeneratorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('photos:make-duplicates
    {--photos=20 : Originals to degrade}
    {--seed=7 : Seed of the selection and of the variant kinds}
    {--fresh : Remove the augmented photos and their files first}')]
#[Description('Write degraded copies of imported photos (known near-duplicates) on the same listing')]
final class MakeDuplicatePhotosCommand extends Command
{
    public function handle(PhotoDuplicateGeneratorService $service): int
    {
        $photos = filter_var($this->option('photos'), FILTER_VALIDATE_INT);
        $seed = filter_var($this->option('seed'), FILTER_VALIDATE_INT);

        if ($photos === false || $photos < 1 || $seed === false) {
            $this->error('--photos must be a positive whole number and --seed a whole number.');

            return self::INVALID;
        }

        try {
            $report = $service->generate($photos, $seed, (bool) $this->option('fresh'));
        } catch (PhotoImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Removed first: {$report->removed}");
        $this->info("Originals used: {$report->originals}");
        $this->info("Variants stored: {$report->variantsStored}");
        $this->line("Already stored: {$report->alreadyStored}");
        $this->line("Unreadable originals: {$report->unreadableOriginals}");
        $this->line('Next: ddev artisan photos:embed --wait (the variants need an image vector).');

        return self::SUCCESS;
    }
}
