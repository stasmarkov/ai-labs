<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\PlaygroundRunService;
use Illuminate\Foundation\Http\FormRequest;

final class ListPlaygroundRunsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['limit' => ['nullable', 'integer', 'between:1,'.PlaygroundRunService::MAX_LIMIT]];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? PlaygroundRunService::DEFAULT_LIMIT);
    }
}
