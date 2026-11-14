<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a research run is: still going, or how it ended.
 */
enum ResearchRunStatus: string
{
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Refused = 'refused';

    public function hasEnded(): bool
    {
        return $this !== self::Running;
    }
}
