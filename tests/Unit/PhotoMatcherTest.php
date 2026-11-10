<?php

declare(strict_types=1);

use App\DTOs\PhotoListingCandidate;
use App\Services\PhotoMatcher;

test('AC-2 picks the nearest year, then the lowest id, skipping used listings', function () {
    $candidates = [
        new PhotoListingCandidate(5, 2013),
        new PhotoListingCandidate(2, 2011),
        new PhotoListingCandidate(9, 2012),
        new PhotoListingCandidate(7, 2000),
    ];
    $matcher = new PhotoMatcher;

    expect($matcher->nearest($candidates, 2012, [])->id)->toBe(9)
        ->and($matcher->nearest($candidates, 2012, [9])->id)->toBe(2)
        ->and($matcher->nearest($candidates, 2012, [9, 2])->id)->toBe(5)
        ->and($matcher->nearest($candidates, 2012, [9, 2, 5])->id)->toBe(7)
        ->and($matcher->nearest($candidates, 2012, [9, 2, 5, 7]))->toBeNull()
        ->and($matcher->nearest([], 2012, []))->toBeNull();
});
