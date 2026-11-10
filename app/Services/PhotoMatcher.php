<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PhotoListingCandidate;

final readonly class PhotoMatcher
{
    /**
     * The unused listing with the nearest year, the lowest id on a tie.
     *
     * @param  list<PhotoListingCandidate>  $candidates
     * @param  list<int>  $usedListingIds
     */
    public function nearest(array $candidates, int $year, array $usedListingIds): ?PhotoListingCandidate
    {
        $used = array_flip($usedListingIds);
        $best = null;

        foreach ($candidates as $candidate) {
            if (isset($used[$candidate->id])) {
                continue;
            }

            if ($best === null || $this->isCloser($candidate, $best, $year)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function isCloser(PhotoListingCandidate $candidate, PhotoListingCandidate $best, int $year): bool
    {
        return [abs($candidate->year - $year), $candidate->id] < [abs($best->year - $year), $best->id];
    }
}
