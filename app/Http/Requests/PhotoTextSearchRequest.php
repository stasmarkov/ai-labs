<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PhotoTextSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The length and text rules live in the service, which owns the 2 to 200 character window.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string'],
        ];
    }

    public function searchText(): string
    {
        return (string) $this->validated('q');
    }
}
