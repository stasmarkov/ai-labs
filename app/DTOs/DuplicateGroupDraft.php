<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A group of near-duplicate photos before it is stored; split is true when the group is a part of a larger component
 * that was cut to respect the size cap.
 */
final readonly class DuplicateGroupDraft
{
    /**
     * @param  list<DuplicateMember>  $members  in ascending photo id order
     */
    public function __construct(
        public array $members,
        public bool $split,
    ) {}
}
