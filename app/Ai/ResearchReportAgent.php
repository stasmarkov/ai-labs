<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Attributes\Temperature;

#[Temperature(0.2)]
final class ResearchReportAgent extends ResearchAgent
{
    protected function promptKey(): string
    {
        return 'research_report';
    }
}
