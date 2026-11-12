<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PhotoLabRepositoryInterface;
use App\DTOs\PhotoExtractionRequest;
use App\DTOs\PhotoLabDetail;
use App\DTOs\PhotoLabItem;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

final readonly class EloquentPhotoLabRepository implements PhotoLabRepositoryInterface
{
    public function paginate(PhotoExtractionRequest $request, int $page, int $perPage): LengthAwarePaginator
    {
        // Only the columns the grid needs: the 512-number embedding stays in the database.
        return ListingPhoto::query()
            ->select(['id', 'listing_id', 'path', 'variant'])
            ->with(['listing', 'extractions' => $this->forRequest($request)])
            ->orderBy('id')
            ->paginate($perPage, page: $page)
            ->through(fn (ListingPhoto $photo): PhotoLabItem => new PhotoLabItem(
                $photo->id,
                $this->url($photo->path),
                $this->title($photo),
                $photo->extractions->first()?->status,
                $photo->variant?->value,
            ));
    }

    public function find(int $photoId, PhotoExtractionRequest $request): ?PhotoLabDetail
    {
        $photo = ListingPhoto::query()
            ->select(['id', 'listing_id', 'path'])
            ->with(['listing', 'extractions' => fn ($query) => $query->orderByDesc('updated_at')])
            ->find($photoId);

        if ($photo === null) {
            return null;
        }

        $isCurrent = fn ($row): bool => $row->provider === $request->provider && $row->model === $request->model && $row->prompt_version === $request->promptVersion;
        $extraction = $photo->extractions->first($isCurrent);
        $others = $photo->extractions->reject($isCurrent)->map(fn ($row): array => [
            'provider' => $row->provider,
            'model' => $row->model,
            'prompt_version' => $row->prompt_version,
            'status' => $row->status,
            'latency_ms' => $row->latency_ms,
            'data' => $row->data,
            'run_href' => ($row->invocation_ids ?? []) === [] ? null : route('ai.runs.show', ['invocationId' => last($row->invocation_ids)], false),
        ])->values()->all();

        return new PhotoLabDetail(
            $photo->id,
            $this->url($photo->path),
            $this->title($photo),
            $extraction?->status,
            $extraction->attempts ?? 0,
            $extraction?->latency_ms,
            $extraction?->data,
            $extraction?->errors,
            $extraction->invocation_ids ?? [],
            $others,
            $photo->listing->make,
            $photo->listing->model,
        );
    }

    public function firstId(): ?int
    {
        $id = ListingPhoto::query()->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return \Closure(HasMany<PhotoExtraction, ListingPhoto>|Builder<PhotoExtraction>): void
     */
    private function forRequest(PhotoExtractionRequest $request): \Closure
    {
        return fn ($query) => $query
            ->where('provider', $request->provider)
            ->where('model', $request->model)
            ->where('prompt_version', $request->promptVersion);
    }

    /**
     * The public path of a stored photo, relative so it works on any host.
     */
    private function url(string $path): string
    {
        return (string) parse_url(Storage::disk('public')->url($path), PHP_URL_PATH);
    }

    private function title(ListingPhoto $photo): string
    {
        return "{$photo->listing->year} {$photo->listing->make} {$photo->listing->model}";
    }
}
