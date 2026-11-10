<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LabHome
{
    /**
     * @param  list<array{label: string, command: string, note: string|null}>  $runSteps
     * @param  list<array{key: string, title: string, route: string, slice: string, guide: array{path: string, anchor: string|null}, code: string|null}>  $capabilities
     * @param  list<array{key: string, title: string, slice: string}>  $coming
     * @param  list<array{key: string, title: string, summary: string, guide: array{path: string, anchor: string|null}}>  $serverModes
     * @param  array{path: string, anchor: string|null, note: string}  $numbers
     * @param  array{path: string, anchor: string|null}  $guide
     */
    public function __construct(
        public string $title,
        public string $about,
        public array $runSteps,
        public string $labHref,
        public array $capabilities,
        public array $coming,
        public array $serverModes,
        public array $numbers,
        public array $guide,
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
            'capabilities' => $this->capabilities,
            'coming' => $this->coming,
            'serverModes' => $this->serverModes,
            'numbers' => $this->numbers,
            'guide' => $this->guide,
        ];
    }
}
