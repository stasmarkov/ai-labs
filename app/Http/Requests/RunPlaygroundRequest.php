<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\PlaygroundRunRequest;
use App\DTOs\PlaygroundTarget;
use App\Http\Requests\Concerns\RequiresJsonBody;
use App\Rules\ValidUtf8Text;
use App\Services\PlaygroundRunner;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checks the shape of a run request. The rules that name a reason (the number of targets, an enabled provider, a listed
 * model, one prompt source, the ranges of the settings) belong to the runner, which answers them with a stable code.
 */
final class RunPlaygroundRequest extends FormRequest
{
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
        return [
            'prompt_version_id' => ['nullable', 'integer', 'min:1'],
            'system_prompt' => ['nullable', 'string', new ValidUtf8Text],
            'input' => ['required', 'string', 'max:'.PlaygroundRunner::MAX_INPUT_LENGTH, new ValidUtf8Text],
            'targets' => ['required', 'array', 'max:10'],
            'targets.*' => ['array'],
            'targets.*.provider' => ['required', 'string', 'max:40'],
            'targets.*.model' => ['required', 'string', 'max:120', new ValidUtf8Text],
            'temperature' => ['nullable', 'numeric', 'decimal:0,2'],
            'max_tokens' => ['nullable', 'integer'],
        ];
    }

    public function toRunRequest(): PlaygroundRunRequest
    {
        $data = $this->validated();

        return new PlaygroundRunRequest(
            isset($data['prompt_version_id']) ? (int) $data['prompt_version_id'] : null,
            isset($data['system_prompt']) ? (string) $data['system_prompt'] : null,
            (string) $data['input'],
            array_map(fn (array $target): PlaygroundTarget => new PlaygroundTarget((string) $target['provider'], (string) $target['model']), array_values($data['targets'])),
            isset($data['temperature']) ? (float) $data['temperature'] : null,
            isset($data['max_tokens']) ? (int) $data['max_tokens'] : null,
        );
    }
}
