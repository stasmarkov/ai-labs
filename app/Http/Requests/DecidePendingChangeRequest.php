<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of Confirm and Reject: the nonce the change page was rendered with.
 */
final class DecidePendingChangeRequest extends FormRequest
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
        return ['nonce' => ['required', 'string', 'max:100']];
    }

    public function nonce(): string
    {
        return (string) $this->validated('nonce');
    }
}
