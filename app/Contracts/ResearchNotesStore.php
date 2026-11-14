<?php

declare(strict_types=1);

namespace App\Contracts;

interface ResearchNotesStore
{
    /**
     * Keeps the note of one sub-question in the run's working notes.
     *
     * @param  array<string, mixed>  $note
     */
    public function put(string $runId, int $subQuestionIndex, array $note): void;

    /**
     * The run's notes by sub-question index, in index order; empty when the run has none or they expired.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(string $runId): array;
}
