<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PhotoTextSearchRepositoryInterface;
use App\DTOs\VectorQuery;
use Illuminate\Support\Facades\DB;

final readonly class EloquentPhotoTextSearchRepository implements PhotoTextSearchRepositoryInterface
{
    public function __construct(private PhotoVectorRepository $vectors) {}

    public function hasVectors(string $model): bool
    {
        return DB::table('listing_photos')->whereNotNull('embedding')->where('embedding_model', $model)->exists();
    }

    public function nearestToVector(array $vector, string $model, int $limit, int $efSearch): array
    {
        return $this->vectors->nearest(VectorQuery::fromVector($vector, $model, $limit, $efSearch));
    }
}
