<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PlaygroundVersionFields;
use App\Http\Requests\Concerns\RequiresJsonBody;
use Illuminate\Foundation\Http\FormRequest;

final class AddPlaygroundVersionRequest extends FormRequest
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
        return $this->versionRules();
    }
}
