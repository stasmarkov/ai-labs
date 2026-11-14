<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NamesProvider;
use App\Rules\ValidUtf8Text;
use App\Services\ResearchRunner;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

final class StartResearchRunRequest extends FormRequest
{
    use NamesProvider;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only a JSON body is accepted, which keeps plain forms and links from starting a model run. Another site's script
     * is refused by the route (RefuseCrossSiteRequests).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            throw new UnsupportedMediaTypeHttpException('Send the topic as a JSON body.');
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'topic' => ['required', 'string', 'min:'.ResearchRunner::MIN_TOPIC_LENGTH, 'max:'.ResearchRunner::MAX_TOPIC_LENGTH, new ValidUtf8Text],
            'provider' => $this->providerRules(),
        ];
    }

    public function topic(): string
    {
        return (string) $this->validated('topic');
    }
}
