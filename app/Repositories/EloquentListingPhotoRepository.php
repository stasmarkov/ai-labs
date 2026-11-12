<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\EmbeddingIndexCount;
use App\DTOs\NewListingPhoto;
use App\DTOs\PhotoCaptionRow;
use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoListingCandidate;
use App\DTOs\PhotoToEmbed;
use App\Models\Listing;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class EloquentListingPhotoRepository implements ListingPhotoRepositoryInterface
{
    private const NO_TEXT = [
        'text_embedding' => null,
        'text_embedding_model' => null,
        'text_source' => null,
        'text_hash' => null,
        'text_embedded_at' => null,
    ];

    private const STALE = '(embedding IS NULL OR embedding_model IS DISTINCT FROM ?)';

    public function candidatesForModel(string $make, string $model): array
    {
        return Listing::query()
            ->where('make', $make)
            ->where('model', $model)
            ->orderBy('id')
            ->get(['id', 'year'])
            ->map(fn (Listing $listing): PhotoListingCandidate => new PhotoListingCandidate($listing->id, $listing->year))
            ->all();
    }

    public function listingIdsWithPhoto(): array
    {
        return ListingPhoto::query()->distinct()->pluck('listing_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function countListingsWithPhoto(): int
    {
        return ListingPhoto::query()->distinct()->count('listing_id');
    }

    public function hasSha256(string $sha256): bool
    {
        return ListingPhoto::query()->where('sha256', $sha256)->exists();
    }

    public function store(NewListingPhoto $photo, Closure $writeFile): string
    {
        return DB::transaction(function () use ($photo, $writeFile): string {
            $row = ListingPhoto::query()->create([
                'listing_id' => $photo->listingId,
                'path' => '',
                'source' => $photo->source,
                'source_class' => $photo->sourceClass,
                'source_file' => $photo->sourceFile,
                'width' => $photo->width,
                'height' => $photo->height,
                'sha256' => $photo->sha256,
            ]);

            $path = "photos/{$photo->listingId}/{$row->id}.jpg";
            $row->update(['path' => $path]);
            $writeFile($path);

            return $path;
        });
    }

    public function deleteBySource(string $source): array
    {
        $paths = ListingPhoto::query()->where('source', $source)->pluck('path')->all();
        ListingPhoto::query()->where('source', $source)->delete();

        return array_values($paths);
    }

    public function embeddingCount(string $model): EmbeddingIndexCount
    {
        $total = ListingPhoto::query()->count();

        if (! Schema::hasColumn('listing_photos', 'embedding_model')) {
            return new EmbeddingIndexCount($total, 0, 0);
        }

        $embedded = ListingPhoto::query()->whereNotNull('embedding_model');

        return new EmbeddingIndexCount(
            $total,
            (clone $embedded)->count(),
            (clone $embedded)->where('embedding_model', $model)->count(),
        );
    }

    public function clearEmbeddings(?int $limit): int
    {
        $scope = ListingPhoto::query()->whereNotNull('embedding');

        if ($limit !== null) {
            $scope->whereIn('id', ListingPhoto::query()->select('id')->orderBy('id')->limit($limit));
        }

        return $scope->update(['embedding' => null, 'embedding_model' => null, 'embedded_at' => null]);
    }

    public function staleEmbeddingPhotoIds(string $model, ?int $limit): array
    {
        $scope = DB::table('listing_photos')->select(['id', 'embedding', 'embedding_model'])->orderBy('id');

        if ($limit !== null) {
            $scope->limit($limit);
        }

        return DB::query()
            ->fromSub($scope, 'scope')
            ->whereRaw(self::STALE, [$model])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function stalePhotos(array $photoIds, string $model): array
    {
        if ($photoIds === []) {
            return [];
        }

        return DB::table('listing_photos')
            ->whereIn('id', $photoIds)
            ->whereRaw(self::STALE, [$model])
            ->orderBy('id')
            ->get(['id', 'path'])
            ->map(fn (object $row): PhotoToEmbed => new PhotoToEmbed((int) $row->id, $row->path))
            ->all();
    }

    public function storeEmbeddings(array $embeddings, string $model, CarbonImmutable $embeddedAt): int
    {
        if ($embeddings === []) {
            return 0;
        }

        $values = implode(', ', array_fill(0, count($embeddings), '(CAST(? AS bigint), CAST(? AS text))'));
        $bindings = [$model, $embeddedAt->format('Y-m-d H:i:s.uP')];

        foreach ($embeddings as $embedding) {
            array_push($bindings, $embedding->photoId, json_encode($embedding->vector, JSON_THROW_ON_ERROR));
        }

        return DB::affectingStatement(
            "UPDATE listing_photos AS p SET
                embedding = CAST(v.vector AS vector),
                embedding_model = CAST(? AS varchar),
                embedded_at = CAST(? AS timestamptz)
            FROM (VALUES {$values}) AS v(photo_id, vector)
            WHERE p.id = v.photo_id",
            $bindings,
        );
    }

    public function countEmbeddedSince(CarbonImmutable $since): int
    {
        return ListingPhoto::query()->where('embedded_at', '>=', $since->format('Y-m-d H:i:s.uP'))->count();
    }

    public function captionRows(PhotoExtractionRequest $request, ?int $limit): array
    {
        $query = $this->captionQuery($request)->orderBy('p.id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $this->captionRowsOf($query);
    }

    public function captionRowsFor(PhotoExtractionRequest $request, array $photoIds): array
    {
        if ($photoIds === []) {
            return [];
        }

        return $this->captionRowsOf($this->captionQuery($request)->whereIn('p.id', $photoIds)->orderBy('p.id'));
    }

    public function clearTextEmbeddingsInScope(?int $limit): int
    {
        $scope = ListingPhoto::query()->whereNotNull('text_embedding');

        if ($limit !== null) {
            $scope->whereIn('id', ListingPhoto::query()->select('id')->orderBy('id')->limit($limit));
        }

        return $scope->update(self::NO_TEXT);
    }

    public function clearTextEmbeddings(array $photoIds): int
    {
        if ($photoIds === []) {
            return 0;
        }

        return ListingPhoto::query()->whereIn('id', $photoIds)->update(self::NO_TEXT);
    }

    public function storeTextEmbeddings(array $embeddings, string $model, CarbonImmutable $embeddedAt): int
    {
        if ($embeddings === []) {
            return 0;
        }

        $values = implode(', ', array_fill(0, count($embeddings), '(CAST(? AS bigint), CAST(? AS text), CAST(? AS text))'));
        $bindings = [$model, ListingPhoto::TEXT_SOURCE_CAPTION, $embeddedAt->format('Y-m-d H:i:s.uP')];

        foreach ($embeddings as $embedding) {
            array_push($bindings, $embedding->photoId, json_encode($embedding->vector, JSON_THROW_ON_ERROR), $embedding->hash);
        }

        return DB::affectingStatement(
            "UPDATE listing_photos AS p SET
                text_embedding = CAST(v.vector AS vector),
                text_embedding_model = CAST(? AS varchar),
                text_source = CAST(? AS varchar),
                text_hash = CAST(v.hash AS char(64)),
                text_embedded_at = CAST(? AS timestamptz)
            FROM (VALUES {$values}) AS v(photo_id, vector, hash)
            WHERE p.id = v.photo_id",
            $bindings,
        );
    }

    public function countTextEmbeddedSince(CarbonImmutable $since): int
    {
        return ListingPhoto::query()->where('text_embedded_at', '>=', $since->format('Y-m-d H:i:s.uP'))->count();
    }

    private function captionQuery(PhotoExtractionRequest $request): Builder
    {
        return DB::table('listing_photos as p')
            ->leftJoin('photo_extractions as e', function (JoinClause $join) use ($request): void {
                $join->on('e.photo_id', '=', 'p.id')
                    ->where('e.provider', $request->provider)
                    ->where('e.model', $request->model)
                    ->where('e.prompt_version', $request->promptVersion)
                    ->where('e.status', PhotoExtraction::STATUS_COMPLETED);
            })
            ->selectRaw("p.id, e.data->>'caption' AS caption, p.text_hash, p.text_embedding_model, (p.text_embedding IS NOT NULL) AS has_text_vector");
    }

    /**
     * @return list<PhotoCaptionRow>
     */
    private function captionRowsOf(Builder $query): array
    {
        return $query->get()
            ->map(fn (object $row): PhotoCaptionRow => new PhotoCaptionRow(
                (int) $row->id,
                is_string($row->caption) && trim($row->caption) !== '' ? $row->caption : null,
                $row->text_hash === null ? null : (string) $row->text_hash,
                $row->text_embedding_model === null ? null : (string) $row->text_embedding_model,
                (bool) $row->has_text_vector,
            ))
            ->all();
    }
}
