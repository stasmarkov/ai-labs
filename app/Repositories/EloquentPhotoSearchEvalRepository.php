<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PhotoSearchEvalRepositoryInterface;
use App\Models\ListingPhoto;
use App\Models\PhotoExtraction;
use Illuminate\Support\Facades\DB;

final readonly class EloquentPhotoSearchEvalRepository implements PhotoSearchEvalRepositoryInterface
{
    public function embeddedPhotoIds(string $label): array
    {
        return DB::table('listing_photos')
            ->where('source', '<>', ListingPhoto::SOURCE_AUGMENTED)
            ->whereNotNull('embedding')
            ->where('embedding_model', $label)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function dominantColours(string $model, string $promptVersion): array
    {
        $colours = [];

        $rows = DB::table('photo_extractions')
            ->where('status', PhotoExtraction::STATUS_COMPLETED)
            ->where('model', $model)
            ->where('prompt_version', $promptVersion)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get(['photo_id', 'data']);

        foreach ($rows as $row) {
            $data = json_decode((string) $row->data, true);
            $found = is_array($data) && is_array($data['dominant_colours'] ?? null) ? array_values(array_filter($data['dominant_colours'], is_string(...))) : [];

            // The newest completed row wins even with no colours: the photo stays in the colour pool and simply
            // matches no colour query, instead of borrowing an older row's colours or leaving the pool.
            $colours[(int) $row->photo_id] = $found;
        }

        return $colours;
    }
}
