<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AdminAssistantLoop;
use App\Ai\AdminConversation;
use App\Ai\AdminTools\AdminWriteApplier;
use App\Contracts\AiToolStepRepositoryInterface;
use App\DTOs\AdminPendingAction;
use App\DTOs\AdminStepReport;
use App\DTOs\AiToolStepRecord;

/**
 * The only place a write tool is run: after the developer decided on a pending action. It validates the arguments
 * again, writes inside a transaction and gives the proposing tool call its message, so the conversation can go on.
 */
final readonly class AdminAssistantConfirmation
{
    private const MAX_ARGUMENT_LENGTH = 200;

    private const SUMMARY_KEYS = ['total', 'id', 'tag', 'count', 'old_title', 'new_title', 'changed', 'already_tagged', 'status', 'error'];

    public function __construct(
        private AdminWriteApplier $applier,
        private AiToolStepRepositoryInterface $steps,
    ) {}

    /**
     * Validates again, then writes inside a transaction. A write that no longer fits, such as a listing deleted since
     * the proposal, becomes an error step and an error the model is told about; nothing is thrown.
     */
    public function apply(AdminConversation $conversation, AdminPendingAction $pending): AdminStepReport
    {
        $startedAt = hrtime(true);
        $applied = $this->applier->apply($pending->tool, $pending->arguments);

        return $this->settle($conversation, $pending, $applied->outcome, $applied->error, $applied->reply, $startedAt);
    }

    public function reject(AdminConversation $conversation, AdminPendingAction $pending): AdminStepReport
    {
        return $this->settle($conversation, $pending, 'rejected', null, ['status' => 'rejected_by_developer'], hrtime(true));
    }

    /**
     * Drops a pending action nobody decided in time. The model is told, so the history stays well formed.
     */
    public function expire(AdminConversation $conversation): ?AdminStepReport
    {
        $pending = $conversation->pending;

        if ($pending === null) {
            return null;
        }

        $conversation->pending = null;

        return $this->settle($conversation, $pending, 'rejected', 'expired', ['status' => 'expired', 'reason' => 'The change expired without a decision.'], hrtime(true));
    }

    /**
     * Gives the proposing call its tool message, logs the decision under the run that proposed it and reports it.
     *
     * @param  array<string, mixed>  $reply
     */
    private function settle(AdminConversation $conversation, AdminPendingAction $pending, string $outcome, ?string $error, array $reply, int $startedAt): AdminStepReport
    {
        $conversation->messages[] = AdminAssistantLoop::toolMessage($pending->toolCallId, $reply);

        $arguments = array_map(fn (mixed $value): mixed => is_string($value) ? mb_substr($value, 0, self::MAX_ARGUMENT_LENGTH) : $value, $pending->arguments);
        $position = ++$conversation->position;
        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

        rescue(fn () => $this->steps->append($pending->runId, new AiToolStepRecord(
            position: $position,
            tool: $pending->tool,
            arguments: $arguments,
            outcome: $outcome,
            error: $error === null ? null : mb_substr($error, 0, 120),
            resultSummary: array_intersect_key($reply, array_flip(self::SUMMARY_KEYS)),
            durationMs: $durationMs,
        )));

        return new AdminStepReport($position, $pending->tool, $arguments, $outcome, $error, $reply, $durationMs, $pending->runId);
    }
}
