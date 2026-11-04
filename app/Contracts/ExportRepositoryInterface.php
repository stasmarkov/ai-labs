<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ExportListingRow;
use App\DTOs\ExportRecord;

interface ExportRepositoryInterface
{
    /**
     * Listings with their description, in ascending id order, after the given id.
     *
     * @return list<ExportListingRow>
     */
    public function listingRowsAfter(int $afterId, int $limit): array;

    /**
     * Insert the record or replace the one with the same id.
     */
    public function save(ExportRecord $record): void;

    public function find(string $id): ?ExportRecord;
}
