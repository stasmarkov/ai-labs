<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DocsAnswerCacheRepositoryInterface;
use App\DTOs\DocsCacheEntry;
use App\DTOs\DocsCacheNewEntry;
use App\DTOs\DocsCacheVersions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDocsAnswerCacheRepository implements DocsAnswerCacheRepositoryInterface
{
    private const COLUMNS = 'id, question, answer, sources, cited_chunk_ids, retrieved_chunk_ids, provider, prompt_version, answer_model, embedding_model, index_version, source_invocation_id, source_latency_ms, hit_count, created_at';

    public function findCurrent(int $id, DocsCacheVersions $versions, CarbonImmutable $notBefore, ?string $provider = null): ?DocsCacheEntry
    {
        $row = $this->current($versions, $notBefore, $provider)->where('id', $id)->selectRaw(self::COLUMNS)->first();

        return $row === null ? null : $this->entry($row);
    }

    public function nearestCurrent(array $vector, DocsCacheVersions $versions, CarbonImmutable $notBefore, int $efSearch, ?string $provider = null): ?array
    {
        return DB::transaction(function () use ($vector, $versions, $notBefore, $efSearch, $provider): ?array {
            // The version filters run after the index scan, so the iterative scan keeps reading until a current row passes.
            // The order is the distance alone: a second sort key (such as id) makes the planner drop the HNSW index.
            DB::statement('SET LOCAL hnsw.ef_search = '.max(1, $efSearch));
            DB::statement('SET LOCAL hnsw.iterative_scan = strict_order');

            $literal = json_encode($vector, JSON_THROW_ON_ERROR);

            $row = $this->current($versions, $notBefore, $provider)
                ->selectRaw(self::COLUMNS.', 1 - (embedding <=> CAST(? AS vector)) AS similarity', [$literal])
                ->orderByRaw('embedding <=> CAST(? AS vector)', [$literal])
                ->limit(1)
                ->first();

            return $row === null ? null : ['entry' => $this->entry($row), 'similarity' => (float) $row->similarity];
        });
    }

    /**
     * One INSERT ... ON CONFLICT on the version key, so two writers of the same question cannot race. A repeat replaces
     * the question, embedding, answer, sources, ids and source run, resets created_at and keeps hit_count.
     */
    public function upsert(DocsCacheNewEntry $entry): int
    {
        $versions = $entry->versions;
        $now = now();

        $row = DB::selectOne(
            'INSERT INTO docs_answer_cache
                (question, question_hash, embedding, answer, sources, cited_chunk_ids, retrieved_chunk_ids, provider,
                 prompt_version, answer_model, embedding_model, index_version, source_invocation_id, source_latency_ms,
                 created_at, updated_at)
             VALUES (?, ?, CAST(? AS vector), ?, CAST(? AS jsonb), CAST(? AS jsonb), CAST(? AS jsonb), ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (question_hash, prompt_version, answer_model, embedding_model, index_version) DO UPDATE SET
                question = EXCLUDED.question,
                embedding = EXCLUDED.embedding,
                answer = EXCLUDED.answer,
                sources = EXCLUDED.sources,
                cited_chunk_ids = EXCLUDED.cited_chunk_ids,
                retrieved_chunk_ids = EXCLUDED.retrieved_chunk_ids,
                provider = EXCLUDED.provider,
                source_invocation_id = EXCLUDED.source_invocation_id,
                source_latency_ms = EXCLUDED.source_latency_ms,
                created_at = EXCLUDED.created_at,
                updated_at = EXCLUDED.updated_at
             RETURNING id',
            [
                $entry->question,
                $entry->questionHash,
                json_encode($entry->vector, JSON_THROW_ON_ERROR),
                $entry->answer,
                json_encode($entry->sources, JSON_THROW_ON_ERROR),
                json_encode($entry->citedChunkIds, JSON_THROW_ON_ERROR),
                json_encode($entry->retrievedChunkIds, JSON_THROW_ON_ERROR),
                $entry->provider,
                $versions->promptVersion,
                $versions->answerModel,
                $versions->embeddingModel,
                $versions->indexVersion,
                $entry->sourceInvocationId,
                $entry->sourceLatencyMs,
                $now,
                $now,
            ],
        );

        return (int) $row->id;
    }

    public function recordHit(int $id): void
    {
        DB::table('docs_answer_cache')->where('id', $id)->update([
            'hit_count' => DB::raw('hit_count + 1'),
            'last_hit_at' => now(),
        ]);
    }

    public function countByCurrency(DocsCacheVersions $versions, CarbonImmutable $notBefore): array
    {
        $current = $this->current($versions, $notBefore)->count();

        return ['current' => $current, 'stale' => DB::table('docs_answer_cache')->count() - $current];
    }

    public function deleteNotCurrent(DocsCacheVersions $versions, CarbonImmutable $notBefore): array
    {
        return DB::transaction(function () use ($versions, $notBefore): array {
            $currentIds = $this->current($versions, $notBefore)->select('id');

            $rows = DB::table('docs_answer_cache')->whereNotIn('id', $currentIds)->selectRaw(self::COLUMNS)->lockForUpdate()->get();

            DB::table('docs_answer_cache')->whereIn('id', $rows->pluck('id')->all())->delete();

            return $rows->map(fn (object $row): DocsCacheEntry => $this->entry($row))->all();
        });
    }

    private function current(DocsCacheVersions $versions, CarbonImmutable $notBefore, ?string $provider = null): Builder
    {
        return DB::table('docs_answer_cache')
            ->when($provider !== null, fn (Builder $query): Builder => $query->where('provider', $provider))
            ->where('prompt_version', $versions->promptVersion)
            ->where('answer_model', $versions->answerModel)
            ->where('embedding_model', $versions->embeddingModel)
            ->where('index_version', $versions->indexVersion)
            ->where('created_at', '>=', $notBefore);
    }

    private function entry(object $row): DocsCacheEntry
    {
        return new DocsCacheEntry(
            id: (int) $row->id,
            question: $row->question,
            answer: $row->answer,
            sources: json_decode($row->sources, true, 512, JSON_THROW_ON_ERROR),
            citedChunkIds: json_decode($row->cited_chunk_ids, true, 512, JSON_THROW_ON_ERROR),
            retrievedChunkIds: json_decode($row->retrieved_chunk_ids, true, 512, JSON_THROW_ON_ERROR),
            provider: $row->provider,
            versions: new DocsCacheVersions($row->prompt_version, $row->answer_model, $row->embedding_model, $row->index_version),
            sourceInvocationId: $row->source_invocation_id,
            sourceLatencyMs: (int) $row->source_latency_ms,
            hitCount: (int) $row->hit_count,
            createdAt: CarbonImmutable::parse($row->created_at),
        );
    }
}
