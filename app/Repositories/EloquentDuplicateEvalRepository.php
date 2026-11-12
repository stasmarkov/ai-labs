<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\DuplicateEvalRepositoryInterface;
use App\DTOs\DuplicateEvalPhoto;
use Illuminate\Support\Facades\DB;

final readonly class EloquentDuplicateEvalRepository implements DuplicateEvalRepositoryInterface
{
    public function photos(string $label): array
    {
        return DB::table('listing_photos')
            ->whereNotNull('embedding')
            ->where('embedding_model', $label)
            ->orderBy('id')
            ->get(['id', 'duplicate_of', 'variant', 'path', 'width', 'height'])
            ->map(fn (object $row): DuplicateEvalPhoto => new DuplicateEvalPhoto(
                (int) $row->id,
                $row->duplicate_of === null ? (int) $row->id : (int) $row->duplicate_of,
                $row->variant === null ? null : (string) $row->variant,
                (string) $row->path,
                (int) $row->width,
                (int) $row->height,
            ))
            ->all();
    }
}
