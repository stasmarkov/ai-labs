<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\LabCapability;
use App\DTOs\LabHome;

final readonly class LabHomeService
{
    private const GUIDE = 'docs/learning-guide.md';

    public function __construct(private LabOverviewService $overview) {}

    public function home(): LabHome
    {
        $capabilities = $this->overview->capabilities();

        return new LabHome(
            title: 'ai-lab',
            about: 'A study lab for building AI features in Laravel: streamed answers, embeddings, retrieval with citations and structured output, on local and cloud model providers, served by FrankenPHP. Every feature has a page to try, a section in the learning guide and a measured result.',
            runSteps: $this->runSteps(),
            labHref: route('ai.overview', absolute: false),
            capabilities: array_values(array_map($this->shippedCard(...), array_filter($capabilities, fn (LabCapability $capability): bool => $capability->status === 'ready'))),
            coming: array_values(array_map(
                fn (LabCapability $capability): array => ['key' => $capability->key, 'title' => $capability->title, 'slice' => $capability->slice],
                array_filter($capabilities, fn (LabCapability $capability): bool => $capability->status !== 'ready'),
            )),
            serverModes: $this->serverModes(),
            numbers: [
                'path' => 'BENCHMARKS.md',
                'anchor' => 'classic-vs-worker-2026-11-02',
                'note' => 'Every measurement, with the commit, the method and the raw files.',
            ],
            guide: ['path' => self::GUIDE, 'anchor' => null],
        );
    }

    /**
     * @return list<array{label: string, command: string, note: string|null}>
     */
    private function runSteps(): array
    {
        return [
            ['label' => 'Set the project up', 'command' => 'ddev setup', 'note' => null],
            ['label' => 'Pull the text model', 'command' => 'ollama pull '.config('ai.providers.ollama.models.text.default'), 'note' => null],
            ['label' => 'Pull the embedding model', 'command' => 'ollama pull '.config('ai.providers.ollama.models.embeddings.default'), 'note' => null],
            ['label' => 'Use the local models', 'command' => 'AI_PROVIDER=ollama', 'note' => 'Set it in .env. Without it the lab runs on fake-stream, a built-in provider that needs no model.'],
        ];
    }

    /**
     * @return array{key: string, title: string, route: string, slice: string, guide: array{path: string, anchor: string|null}, code: string|null}
     */
    private function shippedCard(LabCapability $capability): array
    {
        return [
            'key' => $capability->key,
            'title' => $capability->title,
            'route' => $capability->route,
            'slice' => $capability->slice,
            'guide' => ['path' => self::GUIDE, 'anchor' => $capability->guide],
            'code' => $capability->code,
        ];
    }

    /**
     * @return list<array{key: string, title: string, summary: string, guide: array{path: string, anchor: string|null}}>
     */
    private function serverModes(): array
    {
        return [
            ['key' => 'frankenphp', 'title' => 'Classic', 'summary' => 'FrankenPHP boots the app on every request, like PHP-FPM but in one Go process.', 'guide' => ['path' => self::GUIDE, 'anchor' => '3-four-ways-to-run-php-behind-a-web-server']],
            ['key' => 'frankenphp-worker', 'title' => 'Worker', 'summary' => 'The app boots once and a pool of workers serves many requests.', 'guide' => ['path' => self::GUIDE, 'anchor' => '4-worker-mode-boot-once-serve-many']],
            ['key' => 'frankenphp-octane', 'title' => 'Octane', 'summary' => 'Laravel Octane drives the same worker model and resets state between requests.', 'guide' => ['path' => self::GUIDE, 'anchor' => '4-worker-mode-boot-once-serve-many']],
        ];
    }
}
