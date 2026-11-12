<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\DuplicateJudgeRequest;
use App\DTOs\DuplicateJudgeTarget;
use App\DTOs\PhotoExtractionStatusCounts;

interface DuplicateJudgementRepositoryInterface
{
    /**
     * Ids, ascending, of every stored duplicate group of the vector label.
     *
     * @return list<int>
     */
    /**
     * The groups of a label still to judge: never a reviewed group, and with a request, never one already judged by
     * that provider, model and prompt.
     *
     * @return list<int>
     */
    public function groupIds(string $label, ?DuplicateJudgeRequest $request = null): array;

    /**
     * Reset or create the judgement as queued and return its id, or null when it is already queued or running.
     */
    public function queue(int $groupId, DuplicateJudgeRequest $request): ?int;

    public function findTarget(int $judgementId): ?DuplicateJudgeTarget;

    public function markRunning(int $judgementId): void;

    /**
     * @param  list<string>  $reasons
     * @param  list<array{photo_id: int, problem: string}>  $issues
     * @param  list<string>  $invocationIds
     */
    public function complete(int $judgementId, int $bestPhotoId, array $reasons, array $issues, int $attempts, array $invocationIds, int $latencyMs): void;

    /**
     * @param  array<string, list<string>>  $errors
     * @param  list<string>  $invocationIds
     */
    public function fail(int $judgementId, array $errors, int $attempts, array $invocationIds, int $latencyMs): void;

    /**
     * @param  list<int>  $judgementIds
     */
    public function statusCounts(array $judgementIds): PhotoExtractionStatusCounts;
}
