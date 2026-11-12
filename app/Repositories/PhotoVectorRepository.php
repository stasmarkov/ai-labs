<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\CaptionSimilarityRepositoryInterface;
use App\DTOs\SimilarPhotoMatch;
use App\DTOs\VectorQuery;
use Illuminate\Support\Facades\DB;

final readonly class PhotoVectorRepository implements CaptionSimilarityRepositoryInterface
{
    /**
     * The inner query orders by distance alone so PostgreSQL can answer it from the HNSW index; the outer query adds
     * the listing columns and the id tie-break.
     *
     * @return list<SimilarPhotoMatch>
     */
    public function nearest(VectorQuery $query): array
    {
        if ($query->vector !== null) {
            $reference = 'CAST(? AS vector)';
            $binding = json_encode($query->vector, JSON_THROW_ON_ERROR);
        } else {
            $reference = '(SELECT q.embedding FROM listing_photos q WHERE q.id = ?)';
            $binding = $query->photoId;
        }

        return DB::transaction(function () use ($query, $reference, $binding): array {
            DB::statement('SET LOCAL hnsw.ef_search = '.max(1, $query->efSearch));
            DB::statement('SET LOCAL enable_seqscan = off');
            // The label filter runs after the index scan; keep scanning until the limit of rows of this model pass it.
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            $nearest = DB::table('listing_photos as p')
                ->whereNotNull('p.embedding')
                ->where('p.embedding_model', $query->model)
                ->selectRaw("p.id, p.listing_id, p.path, p.embedding <=> {$reference} AS distance", [$binding])
                ->orderByRaw("p.embedding <=> {$reference}", [$binding])
                ->limit($query->limit);

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

    public function captionSimilarities(int $referencePhotoId, array $photoIds, string $model): array
    {
        if ($photoIds === []) {
            return [];
        }

        return DB::table('listing_photos as p')
            ->join('listing_photos as q', function ($join) use ($referencePhotoId, $model): void {
                $join->where('q.id', '=', $referencePhotoId)->where('q.text_embedding_model', '=', $model);
            })
            ->whereIn('p.id', $photoIds)
            ->whereNotNull('p.text_embedding')
            ->whereNotNull('q.text_embedding')
            ->where('p.text_embedding_model', $model)
            ->selectRaw('p.id, 1 - (p.text_embedding <=> q.text_embedding) AS similarity')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => (float) $row->similarity])
            ->all();
    }
}
