<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LabHome
{
    /**
     * @param  list<array{label: string, command: string, note: string|null}>  $runSteps
     * @param  list<array{key: string, title: string, route: string, summary: string, guideKey: string, guideHref: string}>  $capabilities
     * @param  list<array{key: string, title: string, route: string, summary: string, guideKey: string, guideHref: string}>  $coming
     * @param  list<array{key: string, title: string, summary: string}>  $serverModes
     */
    public function __construct(
        public string $title,
        public string $about,
        public array $runSteps,
        public string $labHref,
        public string $guidesHref,
        public array $capabilities,
        public array $coming,
        public array $serverModes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        return [
            'title' => $this->title,
            'about' => $this->about,
            'runSteps' => $this->runSteps,
            'labHref' => $this->labHref,
            'guidesHref' => $this->guidesHref,
            'capabilities' => $this->capabilities,
            'coming' => $this->coming,
            'serverModes' => $this->serverModes,
        ];
    }
}
