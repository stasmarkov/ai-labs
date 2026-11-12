<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SimilarPhotosRepositoryInterface;
use App\DTOs\VectorQuery;
use Illuminate\Support\Facades\DB;

final readonly class EloquentSimilarPhotosRepository implements SimilarPhotosRepositoryInterface
{
    public function __construct(private PhotoVectorRepository $vectors) {}

    public function embeddingModel(int $photoId): ?string
    {
        $model = DB::table('listing_photos')->where('id', $photoId)->whereNotNull('embedding')->value('embedding_model');

        return is_string($model) ? $model : null;
    }

    public function listingId(int $photoId): ?int
    {
        $listingId = DB::table('listing_photos')->where('id', $photoId)->value('listing_id');

        return $listingId === null ? null : (int) $listingId;
    }

    public function nearest(int $photoId, string $model, int $limit, int $efSearch): array
    {
        return $this->vectors->nearest(VectorQuery::fromPhoto($photoId, $model, $limit, $efSearch));
    }
}
