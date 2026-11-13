<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class DecideAdminActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only a JSON body is accepted, so another site cannot confirm or reject with a form or a link.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            throw new UnsupportedMediaTypeHttpException('Send the decision as a JSON body.');
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'uuid'],
        ];
    }

    public function conversationId(): string
    {
        return (string) $this->validated('conversation_id');
    }

    public function actionId(): string
    {
        return (string) $this->route('actionId');
    }
}
