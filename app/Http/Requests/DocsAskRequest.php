<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ValidUtf8Text;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class DocsAskRequest extends FormRequest
{
    public const MAX_QUESTION_LENGTH = 500;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only a JSON body is accepted. A cross-site form or link can send form fields or a query string without a CORS
     * preflight, but not "Content-Type: application/json", so another page cannot start a model run; and the question
     * stays out of URLs, access logs and browser history.
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
            'q' => ['required', 'string', 'max:'.self::MAX_QUESTION_LENGTH, new ValidUtf8Text],
        ];
    }

    public function question(): string
    {
        return trim((string) $this->validated('q'));
    }
}
