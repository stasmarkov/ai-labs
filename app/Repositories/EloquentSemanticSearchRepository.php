<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SemanticSearchRepositoryInterface;
use App\DTOs\SemanticMatch;
use Illuminate\Support\Facades\DB;

final readonly class EloquentSemanticSearchRepository implements SemanticSearchRepositoryInterface
{
    /**
     * The inner query orders by distance alone so PostgreSQL can answer it from the HNSW index; the outer query adds the listing columns and the id tie-break.
     */
    public function nearest(array $vector, int $limit, int $efSearch, string $model): array
    {
        return DB::transaction(function () use ($vector, $limit, $efSearch, $model): array {
            // Both settings only last for this transaction. pgvector's cost estimate grows with ef_search, and past about
            // 200 the planner picks a ~260 ms exact sequential scan over the ~11 ms index search, so forbid the scan.
            DB::statement('SET LOCAL hnsw.ef_search = '.max(1, $efSearch));
            DB::statement('SET LOCAL enable_seqscan = off');
            // The embedding_model filter runs after the index scan; while listings are re-embedded for another model
            // both models share one HNSW graph, so keep scanning until $limit rows of this model pass the filter.
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            return $this->query($vector, $limit, $model);
        });
    }

    /**
     * @param  list<float>  $vector
     * @return list<SemanticMatch>
     */
    private function query(array $vector, int $limit, string $model): array
    {
        $literal = json_encode($vector, JSON_THROW_ON_ERROR);

        $nearest = DB::table('listing_documents')
            ->whereNotNull('embedding')
            ->where('embedding_model', $model)
            ->selectRaw('listing_id, body_type, description, embedding <=> CAST(? AS vector) AS distance', [$literal])
            ->orderByRaw('embedding <=> CAST(? AS vector)', [$literal])
            ->limit($limit);

        return DB::query()
            ->fromSub($nearest, 'd')
            ->join('listings as l', 'l.id', '=', 'd.listing_id')
            ->selectRaw('l.id, l.year, l.make, l.model, d.body_type, l.price_cents, l.currency, l.city, d.description, 1 - d.distance AS similarity')
            ->orderBy('d.distance')
            ->orderBy('d.listing_id')
            ->get()
            ->map(fn (object $row): SemanticMatch => new SemanticMatch(
                id: (int) $row->id,
                year: (int) $row->year,
                make: $row->make,
                model: $row->model,
                bodyType: $row->body_type,
                priceCents: (int) $row->price_cents,
                currency: $row->currency,
                city: $row->city,
                description: $row->description,
                similarity: (float) $row->similarity,
            ))
            ->all();
    }
}
