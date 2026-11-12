<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ModerationResult
{
    public const CLASSIFIED = 'classified';

    public const INVALID = 'invalid';

    public const FAILED = 'failed';

    /**
     * @param  self::CLASSIFIED|self::INVALID|self::FAILED  $status
     * @param  list<string>  $reasons
     * @param  list<string>  $invocationIds
     * @param  array<string, list<string>>  $errors  what the last rejected answer got wrong, or the transport failure
     * @param  float|null  $modelRisk  the risk the model gave, before any rule floor raised it
     * @param  list<string>  $floors  the rules that raised the risk or added a reason: "injection_pattern", "vin_reported"
     */
    public function __construct(
        public string $status,
        public ?float $risk,
        public ?bool $risky,
        public array $reasons,
        public ?string $explanation,
        public ModerationFactsResult $facts,
        public float $threshold,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public int $attempts,
        public array $invocationIds,
        public array $errors = [],
        public ?float $modelRisk = null,
        public array $floors = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'risk' => $this->risk,
            'risky' => $this->risky,
            'reasons' => $this->reasons,
            'explanation' => $this->explanation,
            'facts' => $this->facts->toArray(),
            'threshold' => $this->threshold,
            'provider' => $this->provider,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'attempts' => $this->attempts,
            'invocation_ids' => $this->invocationIds,
            'errors' => $this->errors,
            'model_risk' => $this->modelRisk,
            'floors' => $this->floors,
        ];
    }
}
