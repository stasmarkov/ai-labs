<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\DocsCacheEntry;
use App\DTOs\DocsCacheNewEntry;
use App\DTOs\DocsCacheVersions;
use Carbon\CarbonImmutable;

interface DocsAnswerCacheRepositoryInterface
{
    /**
     * The entry with this id when it carries exactly these versions and was created at or after $notBefore.
     */
    public function findCurrent(int $id, DocsCacheVersions $versions, CarbonImmutable $notBefore): ?DocsCacheEntry;

    /**
     * The current entry nearest the vector by cosine, with its similarity; null when there is none.
     *
     * @param  list<float>  $vector
     * @return array{entry: DocsCacheEntry, similarity: float}|null
     */
    public function nearestCurrent(array $vector, DocsCacheVersions $versions, CarbonImmutable $notBefore, int $efSearch): ?array;

    /**
     * Insert, or replace the entry with the same question hash and versions (keeping its hit count); returns its id.
     */
    public function upsert(DocsCacheNewEntry $entry): int;

    /**
     * Counts one served hit on the entry with a single atomic update.
     */
    public function recordHit(int $id): void;

    /**
     * How many entries are current and how many are not (another version, or older than $notBefore).
     *
     * @return array{current: int, stale: int}
     */
    public function countByCurrency(DocsCacheVersions $versions, CarbonImmutable $notBefore): array;

    /**
     * Deletes every entry that is not current and returns what was deleted, so the caller can drop their exact keys.
     *
     * @return list<DocsCacheEntry>
     */
    public function deleteNotCurrent(DocsCacheVersions $versions, CarbonImmutable $notBefore): array;
}
