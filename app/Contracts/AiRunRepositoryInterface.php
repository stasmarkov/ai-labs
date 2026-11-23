<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\AiRunDetail;
use App\DTOs\AiRunRecord;
use App\DTOs\AiRunSummary;
use App\DTOs\DocsCacheRunCounts;
use Carbon\CarbonImmutable;

interface AiRunRepositoryInterface
{
    public function save(AiRunRecord $record): void;

    /**
     * The newest runs, latest first.
     *
     * @return list<AiRunSummary>
     */
    public function recent(int $limit): array;

    public function findByInvocationId(string $invocationId): ?AiRunDetail;

    /**
     * The runs with the given invocation ids, in one query, keyed by invocation id; missing ids are left out.
     *
     * @param  list<string>  $invocationIds
     * @return array<string, AiRunSummary>
     */
    public function summariesByInvocationIds(array $invocationIds): array;

    /**
     * The message stored with a failed run (already cleaned by the recorder), null when the run is missing or has none.
     */
    public function storedErrorMessage(string $invocationId): ?string;

    /**
     * Completed and cached DocsAnswerAgent runs created at or after $since (all of them when null).
     */
    public function docsCacheRuns(?CarbonImmutable $since): DocsCacheRunCounts;
}
