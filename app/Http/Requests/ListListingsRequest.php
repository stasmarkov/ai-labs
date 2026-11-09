<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\ListingFilter;
use App\Rules\ListingFilterRules;
use Illuminate\Foundation\Http\FormRequest;

final class ListListingsRequest extends FormRequest
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
        return ListingFilterRules::rules() + [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function toFilter(): ListingFilter
    {
        $data = $this->validated();

        return ListingFilter::fromValidated($data, (int) ($data['per_page'] ?? 20), (int) ($data['page'] ?? 1));
    }
}
