<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one stored step of a research run ended.
 */
enum ResearchStepStatus: string
{
    case Ok = 'ok';
    case Retried = 'retried';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
