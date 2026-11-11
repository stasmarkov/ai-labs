<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTOs\AiSettingChoice;
use App\Services\AiSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SaveAiSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = 'settings-'.$this->feature();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'max:40'],
            'model' => ['required', 'string', 'max:120'],
            'reindex_acknowledged' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $service = app(AiSettingsService::class);
            $choice = $this->toChoice();
            $error = $service->modelError($choice->feature, $choice->provider, $choice->model);

            if ($error !== null) {
                $validator->errors()->add('model', $error);

                return;
            }

            if ($service->changesEmbeddingModel($choice->feature, $choice->provider, $choice->model) && ! $this->boolean('reindex_acknowledged')) {
                $validator->errors()->add('reindex_acknowledged', 'Confirm that you will re-index: search answers 409 until you do.');
            }
        }];
    }

    public function feature(): string
    {
        return (string) $this->route('feature');
    }

    public function toChoice(): AiSettingChoice
    {
        return new AiSettingChoice($this->feature(), (string) $this->input('provider'), (string) $this->input('model'));
    }
}
