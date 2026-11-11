<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SimilarPhotosRepositoryInterface;
use App\DTOs\SimilarPhotoMatch;
use Illuminate\Support\Facades\DB;

final readonly class EloquentSimilarPhotosRepository implements SimilarPhotosRepositoryInterface
{
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

    /**
     * The inner query orders by distance alone so PostgreSQL can answer it from the HNSW index; the outer query adds
     * the listing columns and the id tie-break.
     */
    public function nearest(int $photoId, string $model, int $limit, int $efSearch): array
    {
        return DB::transaction(function () use ($photoId, $model, $limit, $efSearch): array {
            DB::statement('SET LOCAL hnsw.ef_search = '.max(1, $efSearch));
            DB::statement('SET LOCAL enable_seqscan = off');
            // The label filter runs after the index scan; keep scanning until $limit rows of this model pass it.
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            $nearest = DB::table('listing_photos as p')
                ->whereNotNull('p.embedding')
                ->where('p.embedding_model', $model)
                ->selectRaw('p.id, p.listing_id, p.path, p.embedding <=> (SELECT q.embedding FROM listing_photos q WHERE q.id = ?) AS distance', [$photoId])
                ->orderByRaw('p.embedding <=> (SELECT q.embedding FROM listing_photos q WHERE q.id = ?)', [$photoId])
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
