<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\DocsChatTurn;
use App\Http\Requests\Concerns\NamesProvider;
use App\Rules\ValidUtf8Text;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class DocsAskRequest extends FormRequest
{
    use NamesProvider;

    public const MAX_QUESTION_LENGTH = 500;

    public const MAX_TURN_LENGTH = 2000;

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

        // The wildcard rules below are expanded for every element before any rule runs, and "bail" does not stop that,
        // so an oversized array is rejected here on its length alone.
        $history = $this->input('history');
        $max = (int) config('ai.docs.chat.max_history_turns');

        if (is_array($history) && count($history) > $max) {
            throw ValidationException::withMessages(['history' => [trans('validation.max.array', ['attribute' => 'history', 'max' => $max])]]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:'.self::MAX_QUESTION_LENGTH, new ValidUtf8Text],
            'provider' => $this->providerRules(),
            'cache' => ['sometimes', 'boolean:strict'],
            'history' => ['bail', 'nullable', 'list', 'max:'.(int) config('ai.docs.chat.max_history_turns'), $this->alternates(...)],
            'history.*' => ['array:role,content'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:'.self::MAX_TURN_LENGTH, new ValidUtf8Text],
        ];
    }

    /**
     * Roles must alternate, start with "user" and end with "assistant", so every turn has its answer.
     */
    private function alternates(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $expected = 'user';

        foreach ($value as $turn) {
            if (! is_array($turn) || ($turn['role'] ?? null) !== $expected) {
                $fail('The :attribute roles must alternate, starting with user and ending with assistant.');

                return;
            }

            $expected = $expected === 'user' ? 'assistant' : 'user';
        }

        if ($expected !== 'user') {
            $fail('The :attribute roles must alternate, starting with user and ending with assistant.');
        }
    }

    /**
     * @return list<DocsChatTurn>
     */
    public function history(): array
    {
        return array_map(
            fn (array $turn): DocsChatTurn => new DocsChatTurn((string) $turn['role'], trim((string) $turn['content'])),
            array_values((array) ($this->validated('history') ?? [])),
        );
    }

    public function useCache(): bool
    {
        return (bool) ($this->validated('cache') ?? true);
    }

    public function question(): string
    {
        return trim((string) $this->validated('q'));
    }
}
