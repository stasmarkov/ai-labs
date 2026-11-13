<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\PendingChangeRepositoryInterface;
use App\DTOs\PendingChangeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * What a client is told about a pending change. A pending change past its expiry is reported as expired without being
 * written, and the internal `applying` status is reported as `pending`. The one write: a change that has been
 * `applying` for longer than the timeout lost its request before it settled, so it is stored as `failed`.
 */
final readonly class PendingChangeStatusService
{
    /** What a failed change says when the cause is not something the developer could act on. */
    public const FAILURE = 'the change could not be applied';

    public const APPLYING_TIMEOUT_MINUTES = 2;

    public function __construct(private PendingChangeRepositoryInterface $changes) {}

    /**
     * Stores `failed` for every change stuck in `applying`, or for one of them, and returns how many.
     */
    public function failStuck(?string $id = null): int
    {
        return $this->changes->failStaleApplying(CarbonImmutable::now()->subMinutes(self::APPLYING_TIMEOUT_MINUTES), self::FAILURE, $id);
    }

    /**
     * @return array<string, mixed>|null null when the id is not a UUID or no change has it
     */
    public function describe(string $id): ?array
    {
        if (! Str::isUuid($id) || ($change = $this->changes->find($id)) === null) {
            return null;
        }

        if ($change->status === 'applying' && $this->failStuck($id) > 0) {
            return $this->describe($id);
        }

        $status = $this->effectiveStatus($change);
        $description = ['id' => $change->id, 'status' => $status];

        if ($status === 'confirmed') {
            $description['result'] = $change->result;
        }

        if ($status === 'failed') {
            $description['error'] = $change->error;
        }

        return $description;
    }

    public function effectiveStatus(PendingChangeRecord $change): string
    {
        return match (true) {
            $change->status === 'applying' => 'pending',
            $change->status === 'pending' && $change->expiresAt->isPast() => 'expired',
            default => $change->status,
        };
    }
}
