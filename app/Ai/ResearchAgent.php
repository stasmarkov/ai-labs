<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Promptable;

/**
 * What the three research agents share: the "research" feature's provider and model, and a versioned prompt file.
 */
abstract class ResearchAgent implements Agent, HasProviderOptions, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    private ?string $pinnedModel = null;

    /**
     * Runs this agent on the model the run was started with, whatever the settings say now. With usingProvider() it keeps
     * the provider and the model of the run header on every call and on its `ai_runs` row.
     */
    public function usingModel(?string $model): static
    {
        $this->pinnedModel = filled($model) ? $model : null;

        return $this;
    }

    public function model(): string
    {
        $resolved = app(AiProviders::class)->forFeature($this->feature(), $this->providerOverride)->model;

        return $this->pinnedModel ?? $resolved;
    }

    public function modelName(): string
    {
        return $this->pinnedModel ?? app(AiProviders::class)->configured($this->feature(), $this->providerOverride)->model;
    }

    public function instructions(): string
    {
        return trim((string) file_get_contents(resource_path('prompts/'.$this->promptName().'.md')));
    }

    protected function feature(): string
    {
        return 'research';
    }

    /**
     * The prompt's name and version, read from its file name: "research-note.v1.md" is "research-note@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.'.$this->promptKey());
    }

    /**
     * The key under "ai.prompts" that names this agent's prompt file.
     */
    abstract protected function promptKey(): string;
}
