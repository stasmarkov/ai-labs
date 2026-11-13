<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use App\Contracts\AdminListingRepositoryInterface;
use App\DTOs\AdminWriteOutcome;
use Throwable;

/**
 * Runs a write tool after a human decided on it, shared by every confirmation flow. It validates the arguments again
 * and writes inside a transaction. A write that no longer fits, such as a listing deleted since the proposal, becomes
 * an outcome the caller records; nothing is thrown.
 */
final readonly class AdminWriteApplier
{
    public function __construct(
        private AdminToolRegistry $tools,
        private AdminListingRepositoryInterface $listings,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function apply(string $toolName, array $arguments): AdminWriteOutcome
    {
        $tool = $this->tools->find($toolName);

        if (! $tool instanceof AdminWriteTool) {
            return new AdminWriteOutcome('error', 'unknown_tool', ['error' => 'unknown_tool', 'details' => ['The tool '.$toolName.' is not a write tool.']]);
        }

        if (($errors = $tool->validate($arguments)) !== []) {
            return new AdminWriteOutcome('error', 'invalid_at_confirm', ['status' => 'not_applied', 'error' => 'invalid_at_confirm', 'details' => array_slice($errors, 0, 10)]);
        }

        try {
            $reply = $this->listings->transaction(fn (): array => ['status' => 'applied', ...$tool->run($arguments)]);
        } catch (AdminToolFailed $exception) {
            return new AdminWriteOutcome('error', 'tool_failed', ['error' => 'tool_failed', 'details' => [mb_substr($exception->getMessage(), 0, 200)]]);
        } catch (Throwable $exception) {
            report($exception);

            return new AdminWriteOutcome('failed', $exception::class, ['status' => 'failed']);
        }

        return new AdminWriteOutcome('confirmed', null, $reply);
    }
}
