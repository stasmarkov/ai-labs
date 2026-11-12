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
            'w' => ['nullable', 'numeric', 'between:0,1'],
        ];
    }

    public function weight(): ?float
    {
        $w = $this->validated('w');

        return $w === null ? null : (float) $w;
    }
}
