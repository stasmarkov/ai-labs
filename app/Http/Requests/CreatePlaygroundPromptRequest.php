<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PlaygroundVersionFields;
use App\Http\Requests\Concerns\RequiresJsonBody;
use App\Rules\ValidUtf8Text;
use Illuminate\Foundation\Http\FormRequest;

final class CreatePlaygroundPromptRequest extends FormRequest
{
    use PlaygroundVersionFields;
    use RequiresJsonBody;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:200', new ValidUtf8Text]] + $this->versionRules();
    }

    public function name(): string
    {
        return (string) $this->validated('name');
    }
}
