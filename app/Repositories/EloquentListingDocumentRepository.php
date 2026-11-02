<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ListingDocumentRepositoryInterface;
use App\DTOs\ListingDocumentText;
use App\DTOs\ListingFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentListingDocumentRepository implements ListingDocumentRepositoryInterface
{
    private const STALE = "(embedding IS NULL OR embedding_model IS DISTINCT FROM ?
        OR embedded_text_hash IS DISTINCT FROM encode(sha256(convert_to(description, 'UTF8')), 'hex'))";

    public function listingsAfter(int $afterId, int $limit): array
    {
        return DB::table('listings')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'year', 'make', 'model', 'fuel', 'mileage_km'])
            ->map(fn (object $row): ListingFacts => new ListingFacts(
                id: (int) $row->id,
                year: (int) $row->year,
                make: $row->make,
                model: $row->model,
                fuel: $row->fuel,
                mileageKm: (int) $row->mileage_km,
            ))
            ->all();
    }

    public function upsertTexts(array $documents): int
    {
        if ($documents === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($documents), '(?, ?, ?, now(), now())'));
        $bindings = [];

        foreach ($documents as $document) {
            array_push($bindings, $document->listingId, $document->bodyType, $document->description);
        }

        return DB::affectingStatement(
            "INSERT INTO listing_documents (listing_id, body_type, description, created_at, updated_at) VALUES {$placeholders}
            ON CONFLICT (listing_id) DO UPDATE SET
                body_type = excluded.body_type,
                description = excluded.description,
                updated_at = excluded.updated_at
            WHERE (listing_documents.body_type, listing_documents.description)
                IS DISTINCT FROM (excluded.body_type, excluded.description)",
            $bindings,
        );
    }

    public function countListingsWithoutDocument(): int
    {
        return DB::table('listings')
            ->whereNotExists(fn ($query) => $query->from('listing_documents')->whereColumn('listing_documents.listing_id', 'listings.id'))
            ->count();
    }

    public function clearEmbeddings(?int $limit): int
    {
        return DB::affectingStatement(
            'UPDATE listing_documents SET embedding = NULL, embedding_model = NULL, embedded_text_hash = NULL, embedded_at = NULL
            WHERE embedding IS NOT NULL'.($limit === null ? '' : ' AND listing_id IN (SELECT listing_id FROM listing_documents ORDER BY listing_id LIMIT ?)'),
            $limit === null ? [] : [$limit],
        );
    }

    public function staleListingIds(string $model, ?int $limit): array
    {
        $scope = DB::table('listing_documents')->select(['listing_id', 'description', 'embedding', 'embedding_model', 'embedded_text_hash'])->orderBy('listing_id');

        if ($limit !== null) {
            $scope->limit($limit);
        }

        return DB::query()
            ->fromSub($scope, 'scope')
            ->whereRaw(self::STALE, [$model])
            ->orderBy('listing_id')
            ->pluck('listing_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function staleDocuments(array $listingIds, string $model): array
    {
        if ($listingIds === []) {
            return [];
        }

        return DB::table('listing_documents')
            ->whereIn('listing_id', $listingIds)
            ->whereRaw(self::STALE, [$model])
            ->orderBy('listing_id')
            ->get(['listing_id', 'body_type', 'description'])
            ->map(fn (object $row): ListingDocumentText => new ListingDocumentText((int) $row->listing_id, $row->body_type, $row->description))
            ->all();
    }

    public function storeEmbeddings(array $embeddings, string $model, CarbonImmutable $embeddedAt): int
    {
        if ($embeddings === []) {
            return 0;
        }

        $values = implode(', ', array_fill(0, count($embeddings), '(CAST(? AS bigint), CAST(? AS text), CAST(? AS text))'));
        $bindings = [$model, $embeddedAt->format('Y-m-d H:i:s.uP')];

        foreach ($embeddings as $embedding) {
            array_push($bindings, $embedding->listingId, json_encode($embedding->vector, JSON_THROW_ON_ERROR), $embedding->textHash);
        }

        return DB::affectingStatement(
            "UPDATE listing_documents AS d SET
                embedding = CAST(v.vector AS vector),
                embedding_model = CAST(? AS varchar),
                embedded_text_hash = v.hash,
                embedded_at = CAST(? AS timestamptz)
            FROM (VALUES {$values}) AS v(listing_id, vector, hash)
            WHERE d.listing_id = v.listing_id
                AND encode(sha256(convert_to(d.description, 'UTF8')), 'hex') = v.hash",
            $bindings,
        );
    }

    public function countEmbeddedSince(CarbonImmutable $since): int
    {
        return DB::table('listing_documents')->where('embedded_at', '>=', $since->format('Y-m-d H:i:s.uP'))->count();
    }

    public function lastEmbeddedAt(CarbonImmutable $since): ?CarbonImmutable
    {
        $last = DB::table('listing_documents')->where('embedded_at', '>=', $since->format('Y-m-d H:i:s.uP'))->max('embedded_at');

        return $last === null ? null : CarbonImmutable::parse($last);
    }
}
