<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\NewPendingChange;
use App\DTOs\PendingChangeRecord;
use Carbon\CarbonImmutable;

interface PendingChangeRepositoryInterface
{
    public function create(NewPendingChange $change): PendingChangeRecord;

    public function find(string $id): ?PendingChangeRecord;

    /**
     * Claims a pending change that has not expired for applying, with one conditional update. Returns false when it
     * was already claimed, decided, expired or is unknown, so two decisions never both win.
     */
    public function claim(string $id): bool;

    /**
     * Rejects a pending change that has not expired, with one conditional update. Returns false when it cannot.
     */
    public function reject(string $id): bool;

    /**
     * Ends a claimed change as `confirmed` or `failed`. Does nothing for a change that is not being applied.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function settle(string $id, string $status, ?array $result, ?string $error): void;

    /**
     * Ends a change that has been `applying` since `$before` or earlier as `failed` with this error: its request died
     * before it settled. Limited to one change when `$id` is given. Returns how many were ended.
     */
    public function failStaleApplying(CarbonImmutable $before, string $error, ?string $id = null): int;

    /**
     * Stores `expired` for a pending change past its expiry. Returns false when it is not pending or still live.
     */
    public function markExpired(string $id): bool;

    /**
     * Stores `expired` for every pending change past its expiry and returns how many there were.
     */
    public function expireOverdue(): int;

    /**
     * Pending changes that have not expired, newest first.
     *
     * @return list<PendingChangeRecord>
     */
    public function pending(int $limit): array;

    /**
     * The latest confirmed, rejected, failed and expired changes, most recently decided first.
     *
     * @return list<PendingChangeRecord>
     */
    public function recent(int $limit): array;
}
