<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ExportGenerateException;
use App\Services\ExportFileService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('exports:generate
    {--target-mb=50 : Minimum size of the file in MiB}
    {--id=listings : Export id, used as the file name}')]
#[Description('Write the listings CSV export to disk, repeating snapshots until it reaches the target size')]
final class ExportsGenerateCommand extends Command
{
    private const MAX_TARGET_MB = 1024;

    public function handle(ExportFileService $service): int
    {
        $id = (string) $this->option('id');
        $targetMb = $this->option('target-mb');

        if (! ExportFileService::isValidId($id)) {
            $this->error('The id must match '.ExportFileService::ID_PATTERN.'.');

            return self::INVALID;
        }

        if (! is_numeric($targetMb) || (float) $targetMb < 1 || (float) $targetMb > self::MAX_TARGET_MB) {
            $this->error('--target-mb must be a number from 1 to '.self::MAX_TARGET_MB.' (MiB).');

            return self::INVALID;
        }

        try {
            $record = $service->generate($id, (int) ceil((float) $targetMb * 1024 * 1024));
        } catch (ExportGenerateException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Wrote {$record->path}: {$record->bytes} bytes, {$record->rows} rows, sha256 {$record->sha256}.");

        return self::SUCCESS;
    }
}
