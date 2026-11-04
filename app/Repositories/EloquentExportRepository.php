<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ExportRepositoryInterface;
use App\DTOs\ExportListingRow;
use App\DTOs\ExportRecord;
use App\Models\Export;
use Illuminate\Support\Facades\DB;

final readonly class EloquentExportRepository implements ExportRepositoryInterface
{
    public function listingRowsAfter(int $afterId, int $limit): array
    {
        return DB::table('listings')
            ->leftJoin('listing_documents', 'listing_documents.listing_id', '=', 'listings.id')
            ->where('listings.id', '>', $afterId)
            ->orderBy('listings.id')
            ->limit($limit)
            ->get([
                'listings.id', 'listings.make', 'listings.model', 'listings.year', 'listings.price_cents',
                'listings.currency', 'listings.city', 'listings.fuel', 'listings.mileage_km', 'listing_documents.description',
            ])
            ->map(fn (object $row): ExportListingRow => new ExportListingRow(
                listingId: (int) $row->id,
                make: $row->make,
                model: $row->model,
                year: (int) $row->year,
                priceCents: (int) $row->price_cents,
                currency: $row->currency,
                city: $row->city,
                fuel: $row->fuel,
                mileageKm: (int) $row->mileage_km,
                description: $row->description ?? '',
            ))
            ->all();
    }

    public function save(ExportRecord $record): void
    {
        Export::query()->updateOrCreate(
            ['id' => $record->id],
            [
                'path' => $record->path,
                'bytes' => $record->bytes,
                'sha256' => $record->sha256,
                'rows' => $record->rows,
                'generated_at' => $record->generatedAt,
            ],
        );
    }

    public function find(string $id): ?ExportRecord
    {
        $export = Export::query()->find($id);

        return $export === null ? null : new ExportRecord(
            id: $export->id,
            path: $export->path,
            bytes: $export->bytes,
            sha256: $export->sha256,
            rows: $export->rows,
            generatedAt: $export->generated_at,
        );
    }
}
