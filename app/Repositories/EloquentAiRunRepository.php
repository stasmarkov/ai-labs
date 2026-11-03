<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\AiRunDetail;
use App\DTOs\AiRunRecord;
use App\DTOs\AiRunSummary;
use App\Models\AiRun;

final readonly class EloquentAiRunRepository implements AiRunRepositoryInterface
{
    public function save(AiRunRecord $record): void
    {
        AiRun::create([
            'invocation_id' => $record->invocationId,
            'agent' => $record->agent,
            'provider' => $record->provider,
            'model' => $record->model,
            'prompt_version' => $record->promptVersion,
            'tokens_in' => $record->tokensIn,
            'tokens_out' => $record->tokensOut,
            'ttft_ms' => $record->ttftMs,
            'latency_ms' => $record->latencyMs,
            'status' => $record->status,
            'error_class' => $record->errorClass,
            'error_message' => $record->errorMessage,
            'inputs' => $record->inputs,
            'retrieved_chunk_ids' => $record->retrievedChunkIds,
            'cited_chunk_ids' => $record->citedChunkIds,
            'refusal_reason' => $record->refusalReason,
        ]);
    }

    public function recent(int $limit): array
    {
        return AiRun::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AiRun $run): AiRunSummary => $this->toSummary($run))
            ->all();
    }

    /**
     * The error is reported by its exception class only: the stored message can name the
     * internal provider URL or carry the provider's response body, and the lookup is public.
     */
    public function findByInvocationId(string $invocationId): ?AiRunDetail
    {
        $run = AiRun::query()->where('invocation_id', $invocationId)->first();

        return $run === null ? null : new AiRunDetail($this->toSummary($run), $run->prompt_version, $run->error_class === null ? null : class_basename($run->error_class));
    }

    private function toSummary(AiRun $run): AiRunSummary
    {
        return new AiRunSummary(
            invocationId: $run->invocation_id,
            agent: $run->agent,
            provider: $run->provider,
            model: $run->model,
            tokensIn: $run->tokens_in,
            tokensOut: $run->tokens_out,
            ttftMs: $run->ttft_ms,
            latencyMs: $run->latency_ms,
            status: $run->status,
            createdAt: $run->created_at?->utc()->toIso8601String() ?? '',
        );
    }
}
