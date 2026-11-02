<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ListingDocumentRepositoryInterface;

final readonly class ListingDocumentService
{
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private ListingDocumentRepositoryInterface $documents,
        private ListingDocumentGenerator $generator,
    ) {}

    /**
     * Write a description for every listing; returns the rows inserted or changed.
     */
    public function describeAll(): int
    {
        $changed = 0;
        $afterId = 0;

        while (($listings = $this->documents->listingsAfter($afterId, self::CHUNK_SIZE)) !== []) {
            $texts = array_map($this->generator->generate(...), $listings);
            $changed += $this->documents->upsertTexts($texts);
            $afterId = $listings[array_key_last($listings)]->id;
        }

        return $changed;
    }
}
