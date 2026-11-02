<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ListingDocumentService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('listings:describe-all')]
#[Description('Write a deterministic description for every listing into listing_documents')]
final class DescribeAllListingsCommand extends Command
{
    public function handle(ListingDocumentService $service): int
    {
        $changed = $service->describeAll();

        $this->info("Descriptions written or changed: {$changed}");

        return self::SUCCESS;
    }
}
