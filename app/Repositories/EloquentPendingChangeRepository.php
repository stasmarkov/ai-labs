<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PendingChangeRepositoryInterface;
use App\DTOs\NewPendingChange;
use App\DTOs\PendingChangeRecord;
use App\Models\PendingChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentPendingChangeRepository implements PendingChangeRepositoryInterface
{
    private const DECIDED = ['confirmed', 'rejected', 'failed', 'expired'];

    public function create(NewPendingChange $change): PendingChangeRecord
    {
        return $this->record(PendingChange::query()->create([
            'source' => $change->source,
            'tool' => $change->tool,
            'arguments' => $change->arguments,
            'preview' => $change->preview,
            'status' => 'pending',
            'expires_at' => $change->expiresAt,
        ])->refresh());
    }

    public function find(string $id): ?PendingChangeRecord
    {
        $change = PendingChange::query()->find($id);

        return $change === null ? null : $this->record($change);
    }

    public function claim(string $id): bool
    {
        return $this->live($id)->update(['status' => 'applying', 'updated_at' => now()]) === 1;
    }

    public function reject(string $id): bool
    {
        return $this->live($id)->update(['status' => 'rejected', 'decided_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function settle(string $id, string $status, ?array $result, ?string $error): void
    {
        PendingChange::query()
            ->whereKey($id)
            ->where('status', 'applying')
            ->update([
                'status' => $status,
                'result' => $result === null ? null : json_encode($result),
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function failStaleApplying(CarbonImmutable $before, string $error, ?string $id = null): int
    {
        return PendingChange::query()
            ->where('status', 'applying')
            ->where('updated_at', '<=', $before)
            ->when($id !== null, fn (Builder $query) => $query->whereKey($id))
            ->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500), 'decided_at' => now(), 'updated_at' => now()]);
    }

    public function markExpired(string $id): bool
    {
        return PendingChange::query()
            ->whereKey($id)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]) === 1;
    }

    public function expireOverdue(): int
    {
        return PendingChange::query()
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    public function pending(int $limit): array
    {
        return PendingChange::query()
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (PendingChange $change): PendingChangeRecord => $this->record($change))
            ->all();
    }

    public function recent(int $limit): array
    {
        return PendingChange::query()
            ->whereIn('status', self::DECIDED)
            ->latest('updated_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (PendingChange $change): PendingChangeRecord => $this->record($change))
            ->all();
    }

    /**
     * @return Builder<PendingChange>
     */
    private function live(string $id): Builder
    {
        return PendingChange::query()
            ->whereKey($id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    private function record(PendingChange $change): PendingChangeRecord
    {
        return new PendingChangeRecord(
            id: $change->id,
            source: $change->source,
            tool: $change->tool,
            arguments: $change->arguments,
            preview: $change->preview,
            status: $change->status,
            result: $change->result,
            error: $change->error,
            expiresAt: $change->expires_at,
            decidedAt: $change->decided_at,
            createdAt: $change->created_at ?? $change->expires_at,
        );
    }
}
