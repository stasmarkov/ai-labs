<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SemanticSearchRequest extends FormRequest
{
    public const MAX_QUERY_LENGTH = 200;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:'.self::MAX_QUERY_LENGTH],
        ];
    }

    public function searchText(): string
    {
        return trim((string) $this->validated('q'));
    }
}
