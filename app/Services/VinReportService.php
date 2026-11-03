<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ListingRepositoryInterface;
use App\DTOs\SlowListingReport;
use App\Exceptions\VinReportUnavailableException;
use App\Support\VinStubClient;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class VinReportService
{
    public function __construct(
        private ListingRepositoryInterface $listings,
        private VinStubClient $client,
    ) {}

    /**
     * @throws ModelNotFoundException when the listing does not exist.
     * @throws VinReportUnavailableException when the VIN service fails or times out.
     */
    public function reportFor(int $listingId): SlowListingReport
    {
        $detail = $this->listings->findDetail($listingId);
        $vin = 'AP'.str_pad((string) $detail->listing->id, 15, '0', STR_PAD_LEFT);

        $startedAt = hrtime(true);
        $report = $this->client->fetch($vin);

        return new SlowListingReport(
            listingId: $detail->listing->id,
            vin: $vin,
            vinReport: $report,
            waitedMs: (int) round((hrtime(true) - $startedAt) / 1e6),
        );
    }
}
