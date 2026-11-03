<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\RagEvalRepositoryInterface;
use App\DTOs\RagChunkSettings;
use App\DTOs\RagRunTiming;
use App\Models\AiRun;
use App\Models\DocsChunk;
use Illuminate\Support\Facades\DB;

final readonly class EloquentRagEvalRepository implements RagEvalRepositoryInterface
{
    public function chunkCounts(string $model): array
    {
        return [
            'total' => DocsChunk::query()->count(),
            'embedded' => DocsChunk::query()->whereNotNull('embedding')->where('embedding_model', $model)->count(),
        ];
    }

    public function chunkSettings(): array
    {
        return DB::table('docs_documents')
            ->selectRaw('COALESCE(chunk_max_tokens, ?) AS max_tokens, COALESCE(chunk_merge_below_tokens, ?) AS merge_below_tokens', [RagChunkSettings::LEGACY_MAX_TOKENS, RagChunkSettings::LEGACY_MERGE_BELOW_TOKENS])
            ->distinct()
            ->orderBy('max_tokens')
            ->orderBy('merge_below_tokens')
            ->get()
            ->map(fn (object $row): array => ['max_tokens' => (int) $row->max_tokens, 'merge_below_tokens' => (int) $row->merge_below_tokens])
            ->all();
    }

    public function timings(array $invocationIds): array
    {
        if ($invocationIds === []) {
            return [];
        }

        return AiRun::query()
            ->whereIn('invocation_id', $invocationIds)
            ->get(['latency_ms', 'ttft_ms'])
            ->map(fn (AiRun $run): RagRunTiming => new RagRunTiming($run->latency_ms, $run->ttft_ms))
            ->all();
    }
}
