<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PhotoTextSearchRepositoryInterface;
use App\DTOs\SimilarPhotoMatch;
use Illuminate\Support\Facades\DB;

final readonly class EloquentPhotoTextSearchRepository implements PhotoTextSearchRepositoryInterface
{
    public function hasVectors(string $model): bool
    {
        return DB::table('listing_photos')->whereNotNull('embedding')->where('embedding_model', $model)->exists();
    }

    /**
     * The inner query orders by distance alone so PostgreSQL can answer it from the HNSW index; the outer query adds
     * the listing columns and the id tie-break.
     */
    public function nearestToVector(array $vector, string $model, int $limit, int $efSearch): array
    {
        $literal = json_encode($vector, JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($literal, $model, $limit, $efSearch): array {
            DB::statement('SET LOCAL hnsw.ef_search = '.max(1, $efSearch));
            DB::statement('SET LOCAL enable_seqscan = off');
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            $nearest = DB::table('listing_photos as p')
                ->whereNotNull('p.embedding')
                ->where('p.embedding_model', $model)
                ->selectRaw('p.id, p.listing_id, p.path, p.embedding <=> CAST(? AS vector) AS distance', [$literal])
                ->orderByRaw('p.embedding <=> CAST(? AS vector)', [$literal])
                ->limit($limit);

            return DB::query()
                ->fromSub($nearest, 'n')
                ->join('listings as l', 'l.id', '=', 'n.listing_id')
                ->selectRaw('n.id, n.listing_id, n.path, l.year, l.make, l.model, 1 - n.distance AS similarity')
                ->orderBy('n.distance')
                ->orderBy('n.id')
                ->get()
                ->map(fn (object $row): SimilarPhotoMatch => new SimilarPhotoMatch(
                    photoId: (int) $row->id,
                    listingId: (int) $row->listing_id,
                    listingTitle: "{$row->year} {$row->make} {$row->model}",
                    make: $row->make,
                    model: $row->model,
                    path: $row->path,
                    similarity: (float) $row->similarity,
                ))
                ->all();
        });
    }
}
