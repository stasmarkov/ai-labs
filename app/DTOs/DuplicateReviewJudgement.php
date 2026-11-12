<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateReviewJudgement
{
    /**
     * @param  list<string>  $reasons
     * @param  list<array{photo_id: int, problem: string}>  $issues
     * @param  array<string, list<string>>|null  $errors
     */
    public function __construct(
        public string $status,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public int $attempts,
        public ?int $latencyMs,
        public ?int $bestPhotoId,
        public array $reasons,
        public array $issues,
        public ?array $errors,
        public ?string $runHref,
    ) {}

    /**
     * @return array{status: string, provider: string, model: string, promptVersion: string, attempts: int, latencyMs: int|null, bestPhotoId: int|null, reasons: list<string>, issues: list<array{photo_id: int, problem: string}>, errors: array<string, list<string>>|null, runHref: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'provider' => $this->provider,
            'model' => $this->model,
            'promptVersion' => $this->promptVersion,
            'attempts' => $this->attempts,
            'latencyMs' => $this->latencyMs,
            'bestPhotoId' => $this->bestPhotoId,
            'reasons' => $this->reasons,
            'issues' => $this->issues,
            'errors' => $this->errors,
            'runHref' => $this->runHref,
        ];
    }
}
