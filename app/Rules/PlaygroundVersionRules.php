<?php

declare(strict_types=1);

namespace App\Rules;

use App\DTOs\NewPlaygroundVersion;
use App\Services\PlaygroundRunner;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The one definition of what a prompt version may hold. The form requests apply it to the request body, and the prompt
 * service applies it again to the DTO it is given, so a direct call cannot store what the endpoint would refuse.
 */
final class PlaygroundVersionRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'system_prompt' => ['required', 'string', 'max:'.PlaygroundRunner::MAX_SYSTEM_PROMPT_LENGTH, new ValidUtf8Text],
            'note' => ['nullable', 'string', 'max:200', new ValidUtf8Text],
            'temperature' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,'.PlaygroundRunner::MAX_TEMPERATURE],
            'max_tokens' => ['nullable', 'integer', 'between:'.PlaygroundRunner::MIN_MAX_TOKENS.','.PlaygroundRunner::MAX_MAX_TOKENS],
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function validate(NewPlaygroundVersion $version): void
    {
        Validator::make([
            'system_prompt' => $version->systemPrompt,
            'note' => $version->note,
            'temperature' => $version->temperature,
            'max_tokens' => $version->maxTokens,
        ], self::rules())->validate();
    }
}
