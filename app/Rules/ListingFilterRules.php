<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\Fuel;
use Illuminate\Validation\Rule;

final class ListingFilterRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'make' => ['nullable', 'string', 'max:50', new ValidUtf8Text],
            'model' => ['nullable', 'string', 'max:80', new ValidUtf8Text],
            'city' => ['nullable', 'string', 'max:80', new ValidUtf8Text],
            'fuel' => ['nullable', Rule::enum(Fuel::class)],
            'min_year' => ['nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'max_price' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ];
    }
}
