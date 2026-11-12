<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PhotoSimilarRequest extends FormRequest
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
            'w' => ['required', 'numeric', 'between:0,1'],
        ];
    }

    public function weight(): float
    {
        return round((float) $this->validated('w'), 1);
    }
}
