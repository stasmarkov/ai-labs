<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DocsBenchRepositoryInterface;
use App\DTOs\DocsEmbeddingWindow;
use App\DTOs\DocsIndexSizes;
use App\DTOs\DocsSourceStats;
use App\DTOs\SampledChunk;
use App\Models\DocsChunk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDocsBenchRepository implements DocsBenchRepositoryInterface
{
    public function embeddedCount(string $model): int
    {
        return DocsChunk::query()->whereNotNull('embedding')->where('embedding_model', $model)->count();
    }

    public function sourceStats(): array
    {
        return DB::table('docs_chunks as c')
            ->join('docs_documents as d', 'd.id', '=', 'c.document_id')
            ->selectRaw('d.source, count(distinct d.id) AS documents, count(*) AS chunks, min(c.token_estimate) AS min_tokens, percentile_cont(0.5) WITHIN GROUP (ORDER BY c.token_estimate) AS median_tokens, max(c.token_estimate) AS max_tokens, sum(c.oversize::int) AS oversize')
            ->groupBy('d.source')
            ->orderBy('d.source')
            ->get()
            ->map(fn (object $row): DocsSourceStats => new DocsSourceStats(
                (string) $row->source,
                (int) $row->documents,
                (int) $row->chunks,
                (int) $row->min_tokens,
                round((float) $row->median_tokens, 1),
                (int) $row->max_tokens,
                (int) $row->oversize,
            ))
            ->all();
    }

    public function sizes(): DocsIndexSizes
    {
        $row = DB::selectOne(
            'SELECT pg_relation_size(?) AS table_bytes, pg_relation_size(?) AS hnsw_bytes, pg_relation_size(?) AS gin_bytes, pg_total_relation_size(?) AS total_bytes',
            ['docs_chunks', DocsChunk::EMBEDDING_INDEX, DocsChunk::TEXT_INDEX, 'docs_chunks'],
        );

        return new DocsIndexSizes((int) $row->table_bytes, (int) $row->hnsw_bytes, (int) $row->gin_bytes, (int) $row->total_bytes);
    }

    public function embeddingWindow(string $agent): ?DocsEmbeddingWindow
    {
        $row = DB::selectOne(
            "SELECT count(*) AS calls, coalesce(sum(tokens_in), 0) AS tokens_in, min(created_at - latency_ms * interval '1 millisecond') AS started_at, max(created_at) AS finished_at FROM ai_runs WHERE agent = ? AND created_at >= coalesce((SELECT max(indexed_at) FROM docs_documents), '-infinity')",
            [$agent],
        );

        if ($row === null || (int) $row->calls === 0) {
            return null;
        }

        return new DocsEmbeddingWindow((int) $row->calls, (int) $row->tokens_in, CarbonImmutable::parse((string) $row->started_at), CarbonImmutable::parse((string) $row->finished_at));
    }

    public function sampleChunks(int $perSource, string $seed): array
    {
        $ranked = DB::table('docs_chunks as c')
            ->join('docs_documents as d', 'd.id', '=', 'c.document_id')
            ->selectRaw('c.id, d.source, d.path, c.content, c.token_estimate, row_number() OVER (PARTITION BY d.source ORDER BY md5(c.id::text || ?), c.id) AS position', [$seed]);

        return DB::query()
            ->fromSub($ranked, 'r')
            ->where('r.position', '<=', $perSource)
            ->orderBy('r.source')
            ->orderBy('r.position')
            ->get(['r.id', 'r.source', 'r.path', 'r.content', 'r.token_estimate'])
            ->map(fn (object $row): SampledChunk => new SampledChunk((int) $row->id, (string) $row->source, (string) $row->path, (string) $row->content, (int) $row->token_estimate))
            ->all();
    }

    public function pgvectorVersion(): string
    {
        return (string) DB::scalar("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
    }
}
