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
 * @property string|null $cache_kind
 * @property float|null $cache_similarity
 * @property int|null $cache_entry_id
 * @property int|null $saved_latency_ms
 * @property string|null $origin
 * @property int|null $history_turns
 * @property int|null $stopped_after_ms
 */
#[Fillable([
    'invocation_id', 'agent', 'provider', 'model', 'prompt_version', 'tokens_in', 'tokens_out',
    'ttft_ms', 'latency_ms', 'status', 'error_class', 'error_message', 'inputs',
    'retrieved_chunk_ids', 'cited_chunk_ids', 'refusal_reason',
    'cache_kind', 'cache_similarity', 'cache_entry_id', 'saved_latency_ms', 'origin',
    'history_turns', 'stopped_after_ms', 'validation_failed',
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
            'cache_similarity' => 'float',
            'cache_entry_id' => 'integer',
            'saved_latency_ms' => 'integer',
            'history_turns' => 'integer',
            'stopped_after_ms' => 'integer',
            'validation_failed' => 'boolean',
        ];
    }
}
