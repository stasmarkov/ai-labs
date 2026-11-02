<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DocsSearchRepositoryInterface;
use App\DTOs\DocsHit;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDocsSearchRepository implements DocsSearchRepositoryInterface
{
    /**
     * The inner query orders by distance alone so PostgreSQL can answer it from the HNSW index; the outer query adds the document columns and the id tie-break.
     */
    public function nearest(array $vector, string $model, int $limit, int $efSearch): array
    {
        return DB::transaction(function () use ($vector, $model, $limit, $efSearch): array {
            // These settings only last for this transaction; see EloquentSemanticSearchRepository for why the scan is forbidden.
            // The embedding_model filter runs after the index scan, so the iterative scan keeps reading the graph until $limit rows pass it.
            DB::statement('SET LOCAL hnsw.ef_search = '.max(1, $efSearch));
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');
            DB::statement('SET LOCAL enable_seqscan = off');

            $literal = json_encode($vector, JSON_THROW_ON_ERROR);

            $nearest = DB::table('docs_chunks')
                ->whereNotNull('embedding')
                ->where('embedding_model', $model)
                ->selectRaw('id, document_id, heading, body, embedding <=> CAST(? AS vector) AS distance', [$literal])
                ->orderByRaw('embedding <=> CAST(? AS vector)', [$literal])
                ->limit($limit);

            return DB::query()
                ->fromSub($nearest, 'c')
                ->join('docs_documents as d', 'd.id', '=', 'c.document_id')
                ->selectRaw('c.id, d.source, d.path, c.heading, c.body, 1 - c.distance AS value')
                ->orderBy('c.distance')
                ->orderBy('c.id')
                ->get()
                ->map(fn (object $row): DocsHit => $this->hit($row))
                ->all();
        });
    }

    public function textMatches(string $query, int $limit): array
    {
        return DB::table('docs_chunks as c')
            ->join('docs_documents as d', 'd.id', '=', 'c.document_id')
            ->crossJoinSub(DB::query()->selectRaw("websearch_to_tsquery('english', ?) AS q", [$query]), 'tq')
            ->whereRaw('c.tsv @@ tq.q')
            ->selectRaw('c.id, d.source, d.path, c.heading, c.body, ts_rank_cd(c.tsv, tq.q) AS value')
            ->orderByDesc('value')
            ->orderBy('c.id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): DocsHit => $this->hit($row))
            ->all();
    }

    private function hit(object $row): DocsHit
    {
        return new DocsHit(
            id: (int) $row->id,
            source: $row->source,
            path: $row->path,
            heading: $row->heading,
            body: $row->body,
            value: (float) $row->value,
        );
    }
}
