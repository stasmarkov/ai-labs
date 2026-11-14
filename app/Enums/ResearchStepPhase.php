<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The fixed phases of a research run.
 */
enum ResearchStepPhase: string
{
    case Plan = 'plan';
    case Search = 'search';
    case Note = 'note';
    case Report = 'report';
}
