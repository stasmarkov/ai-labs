<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PhotoEvalRepositoryInterface;
use App\DTOs\PhotoEvalSample;
use App\Models\ListingPhoto;

final readonly class EloquentPhotoEvalRepository implements PhotoEvalRepositoryInterface
{
    public function countPhotos(): int
    {
        return ListingPhoto::query()->count();
    }

    public function sample(int $limit): array
    {
        return ListingPhoto::query()
            ->orderBy('sha256')
            ->limit($limit)
            ->get(['id', 'path', 'source_class'])
            ->map(fn (ListingPhoto $photo): PhotoEvalSample => new PhotoEvalSample($photo->id, $photo->path, $photo->source_class))
            ->all();
    }
}
