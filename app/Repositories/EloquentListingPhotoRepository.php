<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\NewListingPhoto;
use App\DTOs\PhotoListingCandidate;
use App\Models\Listing;
use App\Models\ListingPhoto;
use Closure;
use Illuminate\Support\Facades\DB;

final readonly class EloquentListingPhotoRepository implements ListingPhotoRepositoryInterface
{
    public function candidatesForModel(string $make, string $model): array
    {
        return Listing::query()
            ->where('make', $make)
            ->where('model', $model)
            ->orderBy('id')
            ->get(['id', 'year'])
            ->map(fn (Listing $listing): PhotoListingCandidate => new PhotoListingCandidate($listing->id, $listing->year))
            ->all();
    }

    public function listingIdsWithPhoto(): array
    {
        return ListingPhoto::query()->distinct()->pluck('listing_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function countListingsWithPhoto(): int
    {
        return ListingPhoto::query()->distinct()->count('listing_id');
    }

    public function hasSha256(string $sha256): bool
    {
        return ListingPhoto::query()->where('sha256', $sha256)->exists();
    }

    public function store(NewListingPhoto $photo, Closure $writeFile): string
    {
        return DB::transaction(function () use ($photo, $writeFile): string {
            $row = ListingPhoto::query()->create([
                'listing_id' => $photo->listingId,
                'path' => '',
                'source' => $photo->source,
                'source_class' => $photo->sourceClass,
                'source_file' => $photo->sourceFile,
                'width' => $photo->width,
                'height' => $photo->height,
                'sha256' => $photo->sha256,
            ]);

            $path = "photos/{$photo->listingId}/{$row->id}.jpg";
            $row->update(['path' => $path]);
            $writeFile($path);

            return $path;
        });
    }

    public function deleteBySource(string $source): array
    {
        $paths = ListingPhoto::query()->where('source', $source)->pluck('path')->all();
        ListingPhoto::query()->where('source', $source)->delete();

        return array_values($paths);
    }
}
