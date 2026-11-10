<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\NlSearchQuery;
use App\Http\Requests\Concerns\NamesProvider;
use App\Rules\ValidUtf8Text;
use App\Services\NlSearchService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class NlSearchRequest extends FormRequest
{
    use NamesProvider;

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
            'q' => ['required', 'string', 'max:'.self::MAX_QUERY_LENGTH, new ValidUtf8Text],
            'model' => ['nullable', 'string', Rule::in(NlSearchService::allowedModels())],
            'provider' => $this->providerRules(),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    public function toQuery(): NlSearchQuery
    {
        $data = $this->validated();

        return new NlSearchQuery(
            text: trim((string) $data['q']),
            model: $data['model'] ?? null,
            perPage: (int) ($data['per_page'] ?? 20),
            page: (int) ($data['page'] ?? 1),
            provider: $this->provider(),
        );
    }
}
