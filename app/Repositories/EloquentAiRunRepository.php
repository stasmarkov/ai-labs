<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\AiRunRecord;
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
        ]);
    }
}
