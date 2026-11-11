<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PhotoLabDetail
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  array<string, list<string>>|null  $errors
     * @param  list<string>  $invocationIds
     */
    /**
     * @param  list<array{provider: string, model: string, prompt_version: string, status: string, latency_ms: int|null, data: array<string, mixed>|null, run_href: string|null}>  $others  this photo's results for other models or prompt versions
     */
    public function __construct(
        public int $id,
        public string $url,
        public string $title,
        public ?string $status,
        public int $attempts,
        public ?int $latencyMs,
        public ?array $data,
        public ?array $errors,
        public array $invocationIds,
        public array $others = [],
        public string $make = '',
        public string $model = '',
    ) {}

    /**
     * The last attempt's logged run, the one the answer or the failure came from.
     */
    public function lastInvocationId(): ?string
    {
        return $this->invocationIds === [] ? null : $this->invocationIds[array_key_last($this->invocationIds)];
    }

    public function isInFlight(): bool
    {
        return $this->status === 'queued' || $this->status === 'running';
    }

    /**
     * @return array{id: int, url: string, title: string, status: string|null, attempts: int, latency_ms: int|null, data: array<string, mixed>|null, errors: array<string, list<string>>|null, run_href: string|null, others: list<array{provider: string, model: string, prompt_version: string, status: string, latency_ms: int|null, data: array<string, mixed>|null, run_href: string|null}>}
     */
    public function toArray(): array
    {
        $invocationId = $this->lastInvocationId();

        return [
            'id' => $this->id,
            'url' => $this->url,
            'title' => $this->title,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'latency_ms' => $this->latencyMs,
            'data' => $this->data,
            'errors' => $this->errors,
            'run_href' => $invocationId === null ? null : route('ai.runs.show', ['invocationId' => $invocationId], false),
            'others' => $this->others,
        ];
    }
}
