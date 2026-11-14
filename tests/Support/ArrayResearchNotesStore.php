<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\ResearchNotesStore;

/**
 * Notes kept in memory for the life of the object, so a research test needs no Redis; the application binds the Redis store.
 */
final class ArrayResearchNotesStore implements ResearchNotesStore
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $notes = [];

    public function put(string $runId, int $subQuestionIndex, array $note): void
    {
        $this->notes[$runId][$subQuestionIndex] = $note;
    }

    public function all(string $runId): array
    {
        $notes = $this->notes[$runId] ?? [];

        ksort($notes);

        return $notes;
    }
}
