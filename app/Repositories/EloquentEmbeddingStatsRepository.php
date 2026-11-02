<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\EmbeddingStatsRepositoryInterface;
use App\DTOs\EmbeddingCallCounts;
use App\DTOs\ListingDocumentSizes;
use App\Models\ListingDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final readonly class EloquentEmbeddingStatsRepository implements EmbeddingStatsRepositoryInterface
{
    public function queuedJobs(string $queue): int
    {
        // Ask the configured queue, whatever its driver, rather than the database queue's table.
        return Queue::size($queue);
    }

    public function callsBetween(CarbonImmutable $from, CarbonImmutable $to, string $queue): EmbeddingCallCounts
    {
        $calls = DB::table('ai_runs')
            ->where('agent', 'embeddings')
            ->whereBetween('created_at', [$from->format('Y-m-d H:i:s.uP'), $to->format('Y-m-d H:i:s.uP')]);

        $failedBatches = DB::table('failed_jobs')
            ->where('queue', $queue)
            ->whereBetween('failed_at', [$from->format('Y-m-d H:i:s.uP'), $to->format('Y-m-d H:i:s.uP')])
            ->count();

        return new EmbeddingCallCounts($calls->count(), $failedBatches);
    }

    public function firstCallStartedAt(CarbonImmutable $since): ?CarbonImmutable
    {
        $first = DB::scalar(
            "SELECT min(created_at - latency_ms * interval '1 millisecond') FROM ai_runs WHERE agent = 'embeddings' AND created_at >= ?",
            [$since->format('Y-m-d H:i:s.uP')],
        );

        return $first === null ? null : CarbonImmutable::parse((string) $first);
    }

    public function documentSizes(): ListingDocumentSizes
    {
        $row = DB::selectOne(
            'SELECT pg_relation_size(?) AS table_bytes, pg_relation_size(?) AS index_bytes, pg_total_relation_size(?) AS total_bytes',
            ['listing_documents', ListingDocument::EMBEDDING_INDEX, 'listing_documents'],
        );

        return new ListingDocumentSizes((int) $row->table_bytes, (int) $row->index_bytes, (int) $row->total_bytes);
    }
}
