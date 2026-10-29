<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\ListingFilter;
use App\Enums\Fuel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        return [
            'make' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'fuel' => ['nullable', Rule::enum(Fuel::class)],
            'min_year' => ['nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'max_price' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function toFilter(): ListingFilter
    {
        $data = $this->validated();

        return new ListingFilter(
            make: $data['make'] ?? null,
            model: $data['model'] ?? null,
            fuel: isset($data['fuel']) ? Fuel::from($data['fuel']) : null,
            city: $data['city'] ?? null,
            minYear: isset($data['min_year']) ? (int) $data['min_year'] : null,
            maxPriceCents: isset($data['max_price']) ? (int) $data['max_price'] * 100 : null,
            perPage: (int) ($data['per_page'] ?? 20),
            page: (int) ($data['page'] ?? 1),
        );
    }
}
