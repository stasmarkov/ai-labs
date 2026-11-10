<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NamesProvider;
use Illuminate\Foundation\Http\FormRequest;

final class DescribeListingRequest extends FormRequest
{
    use NamesProvider;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['provider' => $this->providerRules()];
    }
}
