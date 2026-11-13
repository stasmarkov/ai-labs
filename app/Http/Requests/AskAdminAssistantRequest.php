<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ValidUtf8Text;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class AskAdminAssistantRequest extends FormRequest
{
    public const MAX_QUESTION_LENGTH = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only a JSON body is accepted, so another site cannot start a model run with a form or a link.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            throw new UnsupportedMediaTypeHttpException('Send the question as a JSON body.');
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:'.self::MAX_QUESTION_LENGTH, new ValidUtf8Text],
            'conversation_id' => ['nullable', 'uuid'],
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }

    public function conversationId(): ?string
    {
        $id = $this->validated('conversation_id');

        return is_string($id) ? $id : null;
    }
}
