<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SemanticBenchRepositoryInterface;
use App\Models\ListingDocument;
use Illuminate\Support\Facades\DB;

final readonly class EloquentSemanticBenchRepository implements SemanticBenchRepositoryInterface
{
    /**
     * The inner query orders by distance alone so PostgreSQL can answer it from the HNSW index; the outer query adds the id tie-break.
     */
    private const NEAREST = 'SELECT listing_id FROM (SELECT listing_id, embedding <=> CAST(? AS vector) AS distance FROM listing_documents WHERE embedding IS NOT NULL ORDER BY embedding <=> CAST(? AS vector) LIMIT ?) AS nearest ORDER BY distance, listing_id';

    public function approximateIds(array $vector, int $limit, int $efSearch): array
    {
        return DB::transaction(function () use ($vector, $limit, $efSearch): array {
            DB::statement('SET LOCAL hnsw.ef_search = '.max(1, $efSearch));

            return $this->ids($vector, $limit);
        });
    }

    /**
     * A session-level setting reset in finally: SET LOCAL inside an outer transaction (tests) would outlive this call.
     */
    public function exactIds(array $vector, int $limit): array
    {
        DB::statement('SET enable_indexscan = off');

        try {
            return $this->ids($vector, $limit);
        } finally {
            DB::statement('RESET enable_indexscan');
        }
    }

    public function efSearch(): int
    {
        return (int) DB::scalar('SHOW hnsw.ef_search');
    }

    public function pgvectorVersion(): string
    {
        return (string) DB::scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
    }

    public function embeddedCount(): int
    {
        return ListingDocument::whereNotNull('embedding')->count();
    }

    public function rebuildIndex(): float
    {
        $index = ListingDocument::EMBEDDING_INDEX;

        $building = $index.'_rebuild';

        // Build next to the old index and swap, so a failed or interrupted build leaves the old index in place.
        DB::statement("DROP INDEX IF EXISTS {$building}");
        $started = hrtime(true);
        DB::statement("CREATE INDEX {$building} ON listing_documents USING hnsw (embedding vector_cosine_ops)");
        $seconds = (hrtime(true) - $started) / 1e9;

        DB::transaction(function () use ($index, $building): void {
            DB::statement("DROP INDEX IF EXISTS {$index}");
            DB::statement("ALTER INDEX {$building} RENAME TO {$index}");
        });

        return $seconds;
    }

    /**
     * @param  list<float>  $vector
     * @return list<int>
     */
    private function ids(array $vector, int $limit): array
    {
        $literal = json_encode($vector, JSON_THROW_ON_ERROR);

        return array_map(
            fn (object $row): int => (int) $row->listing_id,
            DB::select(self::NEAREST, [$literal, $literal, $limit]),
        );
    }
}
