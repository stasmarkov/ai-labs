<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateReviewSummary
{
    public function __construct(
        public int $groups,
        public int $judged,
        public int $reviewed,
        public int $markedForRemoval,
    ) {}

    /**
     * @return array{groups: int, judged: int, reviewed: int, markedForRemoval: int}
     */
    public function toArray(): array
    {
        return ['groups' => $this->groups, 'judged' => $this->judged, 'reviewed' => $this->reviewed, 'markedForRemoval' => $this->markedForRemoval];
    }
}
