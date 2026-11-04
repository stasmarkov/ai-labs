<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ExportMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ServeExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['mode' => ['sometimes', Rule::enum(ExportMode::class)]];
    }

    public function mode(): ExportMode
    {
        return ExportMode::tryFrom((string) $this->query('mode')) ?? ExportMode::Offload;
    }
}
