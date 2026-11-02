<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DocsSearchMode;
use App\Services\DocsSearchService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DocsSearchRequest extends FormRequest
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
        return [
            'q' => [
                'required',
                'string',
                'max:'.DocsSearchService::MAX_QUERY_LENGTH,
                // PostgreSQL rejects NUL bytes and invalid UTF-8 with an error, so they are a 422 here instead of a 500 there.
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && (str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
                        $fail('The :attribute must be valid UTF-8 text.');
                    }
                },
            ],
            'mode' => ['nullable', Rule::enum(DocsSearchMode::class)],
            'k' => ['nullable', 'integer', 'min:1', 'max:'.max(1, (int) config('ai.docs.k_max'))],
        ];
    }

    public function searchText(): string
    {
        return trim((string) $this->validated('q'));
    }

    public function mode(): DocsSearchMode
    {
        return DocsSearchMode::tryFrom((string) $this->validated('mode')) ?? DocsSearchMode::Hybrid;
    }

    public function limit(): int
    {
        return (int) ($this->validated('k') ?? config('ai.docs.k_default'));
    }
}
