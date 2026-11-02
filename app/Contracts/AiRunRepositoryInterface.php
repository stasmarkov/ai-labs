<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\AiRunRecord;

interface AiRunRepositoryInterface
{
    public function save(AiRunRecord $record): void;
}
