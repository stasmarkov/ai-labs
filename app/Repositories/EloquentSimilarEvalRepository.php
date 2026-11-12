<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\SimilarEvalRepositoryInterface;
use App\DTOs\EncoderRunTotals;
use App\DTOs\SimilarEvalPhoto;
use App\Models\ListingPhoto;
use Illuminate\Support\Facades\DB;

final readonly class EloquentSimilarEvalRepository implements SimilarEvalRepositoryInterface
{
    public function embeddedPhotos(string $label): array
    {
        return DB::table('listing_photos as p')
            ->join('listings as l', 'l.id', '=', 'p.listing_id')
            ->where('p.source', '<>', ListingPhoto::SOURCE_AUGMENTED)
            ->whereNotNull('p.embedding')
            ->where('p.embedding_model', $label)
            ->orderBy('p.id')
            ->get(['p.id', 'l.year', 'l.make', 'l.model'])
            ->map(fn (object $row): SimilarEvalPhoto => new SimilarEvalPhoto((int) $row->id, $row->make, $row->model, "{$row->year} {$row->make} {$row->model}"))
            ->all();
    }

    public function embeddedTitles(string $label): array
    {
        return DB::table('listing_photos as p')
            ->join('listings as l', 'l.id', '=', 'p.listing_id')
            ->where('p.source', '<>', ListingPhoto::SOURCE_AUGMENTED)
            ->whereNotNull('p.embedding')
            ->where('p.embedding_model', $label)
            ->distinct()
            ->orderBy('l.year')
            ->orderBy('l.make')
            ->orderBy('l.model')
            ->get(['l.year', 'l.make', 'l.model'])
            ->map(fn (object $row): string => "{$row->year} {$row->make} {$row->model}")
            ->all();
    }

    public function encoderRunTotals(string $agent, string $invocationPrefix, string $provider, string $model): EncoderRunTotals
    {
        $prefix = $invocationPrefix;
        $runQuery = fn () => DB::table('ai_runs')
            ->where('agent', $agent)
            ->where('provider', $provider)
            ->where('model', $model);

        $latest = $runQuery()->where('invocation_id', 'like', $prefix.'%')->orderByDesc('id')->value('invocation_id');

        if ($latest === null) {
            return new EncoderRunTotals(0, 0);
        }

        $runPrefix = substr((string) $latest, 0, strlen($prefix) + 36 + 1);

        $totals = $runQuery()
            ->where('status', 'completed')
            ->where('invocation_id', 'like', $runPrefix.'%')
            ->selectRaw('COALESCE(SUM(inputs), 0) AS photos, COALESCE(SUM(latency_ms), 0) AS latency_ms')
            ->first();

        assert($totals !== null);

        return new EncoderRunTotals((int) $totals->photos, (int) $totals->latency_ms);
    }
}
