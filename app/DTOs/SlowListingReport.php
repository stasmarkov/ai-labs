<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SlowListingReport
{
    /**
     * @param  array<array-key, mixed>  $vinReport
     */
    public function __construct(
        public int $listingId,
        public string $vin,
        public array $vinReport,
        public int $waitedMs,
    ) {}

    /**
     * @return array{listing_id: int, vin: string, vin_report: array<array-key, mixed>, waited_ms: int}
     */
    public function toArray(): array
    {
        return [
            'listing_id' => $this->listingId,
            'vin' => $this->vin,
            'vin_report' => $this->vinReport,
            'waited_ms' => $this->waitedMs,
        ];
    }
}
