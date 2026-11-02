<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class EmbeddingRun
{
    public function __construct(
        public CarbonImmutable $startedAt,
        public int $batch,
        public ?int $limit,
        public int $listings,
        public int $jobs,
    ) {}

    /**
     * @return array{started_at: string, batch: int, limit: int|null, listings: int, jobs: int}
     */
    public function toArray(): array
    {
        return [
            'started_at' => $this->startedAt->format('Y-m-d\TH:i:s.uP'),
            'batch' => $this->batch,
            'limit' => $this->limit,
            'listings' => $this->listings,
            'jobs' => $this->jobs,
        ];
    }

    /**
     * @param  array{started_at: string, batch: int, limit: int|null, listings: int, jobs: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(CarbonImmutable::parse($data['started_at']), $data['batch'], $data['limit'], $data['listings'], $data['jobs']);
    }
}
