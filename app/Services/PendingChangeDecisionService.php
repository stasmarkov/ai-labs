<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AdminTools\AdminWriteApplier;
use App\Contracts\PendingChangeRepositoryInterface;
use App\DTOs\AdminWriteOutcome;
use App\DTOs\PendingChangeRecord;
use App\Exceptions\PendingChangeConflictException;
use Throwable;

/**
 * The only way a proposed change reaches a listing: a developer confirms it here, from the page that was given the
 * change's nonce. A confirm claims the change with one conditional update, so two confirms never both write, then
 * validates again and writes through the shared applier. A change that no longer fits, or whose write throws, ends as
 * `failed` with a generic message; it never stays `applying` and no exception class reaches a client.
 */
final readonly class PendingChangeDecisionService
{
    public function __construct(
        private PendingChangeRepositoryInterface $changes,
        private AdminWriteApplier $applier,
        private PendingChangeNonces $nonces,
    ) {}

    /**
     * @throws PendingChangeConflictException when the change is unknown or no longer pending, or the nonce is wrong
     */
    public function confirm(string $id, string $nonce): PendingChangeRecord
    {
        $change = $this->decidable($id, $nonce);

        if (! $this->changes->claim($id)) {
            throw $this->refusal($id);
        }

        try {
            $this->settle($id, $this->applier->apply($change->tool, $change->arguments));
        } catch (Throwable $exception) {
            report($exception);
            $this->changes->settle($id, 'failed', null, PendingChangeStatusService::FAILURE);
        }

        return $this->existing($id);
    }

    /**
     * @throws PendingChangeConflictException when the change is unknown or no longer pending, or the nonce is wrong
     */
    public function reject(string $id, string $nonce): PendingChangeRecord
    {
        $this->decidable($id, $nonce);

        if (! $this->changes->reject($id)) {
            throw $this->refusal($id);
        }

        return $this->existing($id);
    }

    /**
     * The change, when it is live and the nonce is the one its page was given. The state is checked first, so a second
     * tab that holds a used nonce is told the change is no longer pending, not that it was refused.
     */
    private function decidable(string $id, string $nonce): PendingChangeRecord
    {
        $change = $this->existing($id);

        if ($change->status !== 'pending' || $change->expiresAt->isPast()) {
            throw $this->refusal($id);
        }

        if (! $this->nonces->consume($id, $nonce)) {
            throw PendingChangeConflictException::refused();
        }

        return $change;
    }

    private function settle(string $id, AdminWriteOutcome $applied): void
    {
        match ($applied->outcome) {
            'confirmed' => $this->changes->settle($id, 'confirmed', $applied->reply, null),
            'failed' => $this->changes->settle($id, 'failed', $applied->reply, PendingChangeStatusService::FAILURE),
            default => $this->changes->settle($id, 'failed', $applied->reply, $applied->error),
        };
    }

    private function existing(string $id): PendingChangeRecord
    {
        return $this->changes->find($id) ?? throw PendingChangeConflictException::notPending();
    }

    private function refusal(string $id): PendingChangeConflictException
    {
        $this->changes->markExpired($id);

        return PendingChangeConflictException::notPending();
    }
}
