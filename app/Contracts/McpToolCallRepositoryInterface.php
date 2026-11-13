<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\McpToolCallRecord;

interface McpToolCallRepositoryInterface
{
    public function record(McpToolCallRecord $call): void;

    /**
     * The call that proposed the change, if one was logged.
     */
    public function findByPendingChange(string $pendingChangeId): ?McpToolCallRecord;
}
