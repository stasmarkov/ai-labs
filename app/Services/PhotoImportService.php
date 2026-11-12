<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ListingPhotoRepositoryInterface;
use App\DTOs\NewListingPhoto;
use App\DTOs\PhotoImportReport;
use App\DTOs\PhotoListingCandidate;
use App\Exceptions\PhotoImportException;
use App\Support\PhotoImageResizer;
use App\Support\StanfordCarsDataset;
use Illuminate\Support\Facades\Storage;

final readonly class PhotoImportService
{
    public const SOURCE = 'stanford-cars';

    public function __construct(
        private ListingPhotoRepositoryInterface $photos,
        private StanfordCarsClassParser $parser,
        private PhotoMatcher $matcher,
        private PhotoImageResizer $resizer,
    ) {}

    /**
     * Store the dataset photos whose class matches a catalog model, each on the listing of that model with the nearest year.
     */
    public function import(StanfordCarsDataset $dataset, int $limit, int $perModel, bool $fresh): PhotoImportReport
    {
        // Checked before --fresh deletes anything, so a missing image library never empties the photos first.
        if (! extension_loaded('gd')) {
            throw PhotoImportException::imageLibraryMissing();
        }

        $photos = $dataset->photos();

        if ($fresh) {
            $this->deleteStored();
        }

        $used = $this->photos->listingIdsWithPhoto();
        $candidates = [];
        $perModelCount = [];
        $classes = [];
        $counts = ['stored' => 0, 'already' => 0, 'overCap' => 0, 'noListing' => 0, 'unreadable' => 0];

        foreach ($photos as $photo) {
            $class = $classes[$photo->className] ??= $this->parser->parse($photo->className) ?? false;

            if ($class === false) {
                continue;
            }

            if ($counts['stored'] >= $limit) {
                break;
            }

            $key = $class->make.'|'.$class->model;

            if (($perModelCount[$key] ?? 0) >= $perModel) {
                $counts['overCap']++;

                continue;
            }

            $path = $dataset->imagePath($photo);
            $sha256 = is_file($path) ? hash_file('sha256', $path) : false;

            if ($sha256 === false) {
                $counts['unreadable']++;

                continue;
            }

            if ($this->photos->hasSha256($sha256)) {
                $counts['already']++;
                $perModelCount[$key] = ($perModelCount[$key] ?? 0) + 1;

                continue;
            }

            $candidates[$key] ??= $this->photos->candidatesForModel($class->make, $class->model);
            $listing = $this->matcher->nearest($candidates[$key], $class->year, $used);

            if (! $listing instanceof PhotoListingCandidate) {
                $counts['noListing']++;

                continue;
            }

            try {
                $resized = $this->resizer->resize($path);
            } catch (PhotoImportException) {
                $counts['unreadable']++;

                continue;
            }

            $this->photos->store(
                new NewListingPhoto($listing->id, self::SOURCE, $class->name, $photo->file, $resized->width, $resized->height, $sha256),
                function (string $storedPath) use ($resized): void {
                    if (Storage::disk('public')->put($storedPath, $resized->bytes) === false) {
                        throw PhotoImportException::writeFailed($storedPath);
                    }
                },
            );

            $used[] = $listing->id;
            $perModelCount[$key] = ($perModelCount[$key] ?? 0) + 1;
            $counts['stored']++;
        }

        $matched = count(array_filter($classes, fn (mixed $class): bool => $class !== false));

        return new PhotoImportReport(
            classesMatched: $matched,
            classesSkipped: count($classes) - $matched,
            photosStored: $counts['stored'],
            alreadyImported: $counts['already'],
            skippedOverModelCap: $counts['overCap'],
            skippedNoListing: $counts['noListing'],
            unreadableFiles: $counts['unreadable'],
            listingsWithPhoto: $this->photos->countListingsWithPhoto(),
        );
    }

    private function deleteStored(): void
    {
        $disk = Storage::disk('public');

        // Generated copies point at imported photos and go with them (foreign key cascade), so their files are
        // removed first; otherwise the rows vanish and the files stay on disk for good.
        foreach ([NewListingPhoto::SOURCE_AUGMENTED, self::SOURCE] as $source) {
            foreach ($this->photos->deleteBySource($source) as $path) {
                $disk->delete($path);
            }
        }
    }
}
