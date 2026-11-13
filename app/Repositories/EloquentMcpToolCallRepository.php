<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\McpToolCallRepositoryInterface;
use App\DTOs\McpToolCallRecord;
use App\Models\McpToolCall;

final readonly class EloquentMcpToolCallRepository implements McpToolCallRepositoryInterface
{
    public function record(McpToolCallRecord $call): void
    {
        McpToolCall::query()->create([
            'tool' => $call->tool,
            'arguments' => $call->arguments,
            'result_summary' => $call->resultSummary,
            'outcome' => $call->outcome,
            'error' => $call->error === null ? null : mb_substr($call->error, 0, 500),
            'duration_ms' => $call->durationMs,
            'pending_change_id' => $call->pendingChangeId,
        ]);
    }

    public function findByPendingChange(string $pendingChangeId): ?McpToolCallRecord
    {
        $call = McpToolCall::query()->where('pending_change_id', $pendingChangeId)->oldest('id')->first();

        return $call === null ? null : new McpToolCallRecord(
            tool: $call->tool,
            arguments: $call->arguments,
            resultSummary: $call->result_summary,
            outcome: $call->outcome,
            error: $call->error,
            durationMs: $call->duration_ms,
            pendingChangeId: $call->pending_change_id,
            createdAt: $call->created_at,
        );
    }
}
