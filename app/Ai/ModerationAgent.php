<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\ModerationFactsResult;
use App\DTOs\ModerationInput;
use App\Rules\ModerationRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;

#[Temperature(0)]
final class ModerationAgent implements Agent, HasProviderOptions, HasStructuredOutput, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    private ?string $pinnedModel = null;

    /**
     * Runs this agent on a given text model instead of the configured one. A pinned model is always honoured: with a
     * provider override it is a model of that provider, so the eval files its results under the model that ran.
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

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'risk' => $schema->number()->min(0)->max(1)->required(),
            'reasons' => $schema->array()
                ->items($schema->string()->enum(ModerationRules::REASONS))
                ->min(0)
                ->max(ModerationRules::MAX_REASONS)
                ->required(),
            'explanation' => $schema->string()->min(1)->max(ModerationRules::MAX_EXPLANATION_LENGTH)->required(),
        ];
    }

    /**
     * Sends the listing and the computed facts as two JSON blocks. JSON encoding with escaped tags keeps the listing text
     * from closing its block, so it stays data.
     */
    public function classify(ModerationInput $input, ModerationFactsResult $facts): StructuredAgentResponse
    {
        /** @var StructuredAgentResponse */
        return $this->prompt($this->promptFor($input, $facts));
    }

    public function promptFor(ModerationInput $input, ModerationFactsResult $facts): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT;
        $listing = [
            'title' => $input->title,
            'price_eur' => $input->priceEur,
            'make' => $input->make,
            'model' => $input->model,
            'year' => $input->year,
            'mileage_km' => $input->mileageKm,
            'city' => $input->city,
            'description' => $input->description,
        ];

        return "Rate this listing.\n<listing>\n".json_encode($listing, $flags)."\n</listing>\n<facts>\n".json_encode($facts->toArray(), $flags)."\n</facts>";
    }

    protected function feature(): string
    {
        return 'moderation';
    }

    /**
     * The prompt's name and version, read from its file name: "moderation.v1.md" is "moderation@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.moderation');
    }
}
