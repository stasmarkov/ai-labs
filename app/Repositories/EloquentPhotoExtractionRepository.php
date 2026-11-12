<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PhotoExtractionRepositoryInterface;
use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoExtractionStatusCounts;
use App\DTOs\PhotoExtractionTarget;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use Illuminate\Support\Facades\DB;

final readonly class EloquentPhotoExtractionRepository implements PhotoExtractionRepositoryInterface
{
    public function pendingPhotoIds(PhotoExtractionRequest $request, ?int $limit): array
    {
        $query = ListingPhoto::query()
            ->where('listing_photos.source', '<>', ListingPhoto::SOURCE_AUGMENTED)
            ->whereNotExists(fn ($existing) => $existing->selectRaw('1')
                ->from('photo_extractions')
                ->whereColumn('photo_extractions.photo_id', 'listing_photos.id')
                ->where('photo_extractions.provider', $request->provider)
                ->where('photo_extractions.model', $request->model)
                ->where('photo_extractions.prompt_version', $request->promptVersion))
            ->orderBy('listing_photos.id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_map(intval(...), $query->pluck('listing_photos.id')->all());
    }

    public function deleteFor(PhotoExtractionRequest $request): int
    {
        return PhotoExtraction::query()
            ->where('provider', $request->provider)
            ->where('model', $request->model)
            ->where('prompt_version', $request->promptVersion)
            ->delete();
    }

    public function queue(int $photoId, PhotoExtractionRequest $request): ?int
    {
        // The row is locked while it is checked and reset, so two requests at once queue it only once.
        return DB::transaction(function () use ($photoId, $request): ?int {
            $keys = ['photo_id' => $photoId, 'provider' => $request->provider, 'model' => $request->model, 'prompt_version' => $request->promptVersion];
            $existing = PhotoExtraction::query()->where($keys)->lockForUpdate()->first();

            if ($existing !== null && in_array($existing->status, [PhotoExtraction::STATUS_QUEUED, PhotoExtraction::STATUS_RUNNING], true)) {
                return null;
            }

            return PhotoExtraction::query()->updateOrCreate($keys, [
                'status' => PhotoExtraction::STATUS_QUEUED,
                'attempts' => 0,
                'data' => null,
                'errors' => null,
                'invocation_ids' => null,
                'latency_ms' => null,
            ])->id;
        });
    }

    public function findTarget(int $extractionId): ?PhotoExtractionTarget
    {
        $extraction = PhotoExtraction::query()->with('photo')->find($extractionId);

        return $extraction === null ? null : new PhotoExtractionTarget(
            $extraction->id,
            $extraction->photo->path,
            $extraction->provider,
            $extraction->model,
            $extraction->prompt_version,
        );
    }

    public function markRunning(int $extractionId): void
    {
        PhotoExtraction::query()->whereKey($extractionId)->update(['status' => PhotoExtraction::STATUS_RUNNING]);
    }

    public function complete(int $extractionId, array $data, int $attempts, array $invocationIds, int $latencyMs): void
    {
        PhotoExtraction::query()->whereKey($extractionId)->first()?->update([
            'status' => PhotoExtraction::STATUS_COMPLETED,
            'attempts' => $attempts,
            'data' => $data,
            'errors' => null,
            'invocation_ids' => $invocationIds,
            'latency_ms' => $latencyMs,
        ]);
    }

    public function fail(int $extractionId, array $errors, int $attempts, array $invocationIds, int $latencyMs): void
    {
        PhotoExtraction::query()->whereKey($extractionId)->first()?->update([
            'status' => PhotoExtraction::STATUS_FAILED,
            'attempts' => $attempts,
            'data' => null,
            'errors' => $errors,
            'invocation_ids' => $invocationIds,
            'latency_ms' => $latencyMs,
        ]);
    }

    public function statusCounts(array $extractionIds): PhotoExtractionStatusCounts
    {
        /** @var array<string, int> $counts */
        $counts = PhotoExtraction::query()
            ->whereIn('id', $extractionIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return new PhotoExtractionStatusCounts(
            $counts[PhotoExtraction::STATUS_QUEUED] ?? 0,
            $counts[PhotoExtraction::STATUS_RUNNING] ?? 0,
            $counts[PhotoExtraction::STATUS_COMPLETED] ?? 0,
            $counts[PhotoExtraction::STATUS_FAILED] ?? 0,
        );
    }

    public function countPhotos(): int
    {
        return ListingPhoto::query()->where('source', '<>', ListingPhoto::SOURCE_AUGMENTED)->count();
    }
}
