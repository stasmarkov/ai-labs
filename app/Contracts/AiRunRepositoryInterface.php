<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\AiRunDetail;
use App\DTOs\AiRunRecord;
use App\DTOs\AiRunSummary;

interface AiRunRepositoryInterface
{
    public function save(AiRunRecord $record): void;

    /**
     * The newest runs, latest first.
     *
     * @return list<AiRunSummary>
     */
    public function recent(int $limit): array;

    public function findByInvocationId(string $invocationId): ?AiRunDetail;
}
