<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AiSettingChoice
{
    /** A model id: letters, digits and . _ : / -, starting with a letter or digit, never a ".." segment. */
    public const MODEL_PATTERN = '/^(?!.*\.\.)[A-Za-z0-9][A-Za-z0-9._:\/-]{0,119}$/';

    public function __construct(
        public string $feature,
        public string $provider,
        public string $model,
    ) {}

    /**
     * @return array{feature: string, provider: string, model: string}
     */
    public function toArray(): array
    {
        return ['feature' => $this->feature, 'provider' => $this->provider, 'model' => $this->model];
    }
}
