<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class KeepDuplicatePhotoRequest extends FormRequest
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
            'photo_id' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ];
    }

    public function photoId(): int
    {
        return (int) $this->validated('photo_id');
    }
}
