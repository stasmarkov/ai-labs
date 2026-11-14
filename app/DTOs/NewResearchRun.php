<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\ResearchRunStatus;

/**
 * A research run before it is stored: the topic and the provider, model and prompt versions it will use.
 */
final readonly class NewResearchRun
{
    /**
     * @param  array<string, string>  $promptVersions  by agent: planner, note, report
     */
    public function __construct(
        public string $topic,
        public string $provider,
        public string $model,
        public array $promptVersions,
        public ResearchRunStatus $status = ResearchRunStatus::Running,
        public ?string $error = null,
    ) {}
}
