<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DuplicateGroupRepositoryInterface;
use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\NewListingPhoto;
use App\DTOs\PhotoDuplicateReport;
use App\Enums\PhotoVariant;
use App\Exceptions\PhotoImportException;
use App\Support\PhotoVariantRenderer;
use Illuminate\Support\Facades\Storage;

final readonly class PhotoDuplicateGeneratorService
{
    public function __construct(
        private ListingPhotoRepositoryInterface $photos,
        private PhotoVariantRenderer $renderer,
        private DuplicateGroupRepositoryInterface $groups,
    ) {}

    /**
     * Write 2 to 4 degraded copies of each of the first $count originals of a seeded shuffle, on the original's listing.
     */
    public function generate(int $count, int $seed, bool $fresh): PhotoDuplicateReport
    {
        if (! extension_loaded('gd')) {
            throw PhotoImportException::imageLibraryMissing();
        }

        $removed = $fresh ? $this->deleteAugmented() : 0;

        $originals = $this->photos->originalsWithVector();
        mt_srand($seed);
        shuffle($originals);
        $selected = array_slice($originals, 0, $count);

        $disk = Storage::disk('public');
        $stored = 0;
        $already = 0;
        $unreadable = 0;

        foreach ($selected as $original) {
            $kinds = PhotoVariant::cases();
            shuffle($kinds);
            $kinds = array_slice($kinds, 0, 2 + mt_rand(0, 2));

            $bytes = $disk->exists($original->path) ? $disk->get($original->path) : null;

            if ($bytes === null) {
                $unreadable++;

                continue;
            }

            foreach ($kinds as $kind) {
                try {
                    $variant = $this->renderer->render($bytes, $kind);
                } catch (PhotoImportException) {
                    $unreadable++;

                    continue 2;
                }

                $sha256 = hash('sha256', $variant->bytes);

                if ($this->photos->hasSha256($sha256)) {
                    $already++;

                    continue;
                }

                $this->photos->store(
                    new NewListingPhoto($original->listingId, NewListingPhoto::SOURCE_AUGMENTED, $original->sourceClass, $original->sourceFile, $variant->width, $variant->height, $sha256, $original->id, $kind),
                    function (string $path) use ($disk, $variant): void {
                        if ($disk->put($path, $variant->bytes) === false) {
                            throw PhotoImportException::writeFailed($path);
                        }
                    },
                );
                $stored++;
            }
        }

        return new PhotoDuplicateReport($removed, count($selected), $stored, $already, $unreadable);
    }

    private function deleteAugmented(): int
    {
        // Groups that hold a generated copy go first, with their reviews, judgements and the marks they wrote, so no
        // imported photo is left "kept" or "marked for removal" by a review that no longer exists.
        $this->groups->deleteGroupsHolding(NewListingPhoto::SOURCE_AUGMENTED);

        $disk = Storage::disk('public');
        $paths = $this->photos->deleteBySource(NewListingPhoto::SOURCE_AUGMENTED);

        foreach ($paths as $path) {
            $disk->delete($path);
        }

        return count($paths);
    }
}
