<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $invocation_id
 * @property string $agent
 * @property string $provider
 * @property string $model
 * @property string|null $prompt_version
 * @property int|null $tokens_in
 * @property int|null $tokens_out
 * @property int|null $ttft_ms
 * @property int $latency_ms
 * @property string $status
 * @property string|null $error_class
 * @property string|null $error_message
 * @property int|null $inputs
 * @property list<int>|null $retrieved_chunk_ids
 * @property list<int>|null $cited_chunk_ids
 * @property string|null $refusal_reason
 */
#[Fillable([
    'invocation_id', 'agent', 'provider', 'model', 'prompt_version', 'tokens_in', 'tokens_out',
    'ttft_ms', 'latency_ms', 'status', 'error_class', 'error_message', 'inputs',
    'retrieved_chunk_ids', 'cited_chunk_ids', 'refusal_reason',
])]
class AiRun extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'ttft_ms' => 'integer',
            'latency_ms' => 'integer',
            'inputs' => 'integer',
            'retrieved_chunk_ids' => 'array',
            'cited_chunk_ids' => 'array',
        ];
    }
}
