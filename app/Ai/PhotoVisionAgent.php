<?php

declare(strict_types=1);

namespace App\Ai;

use App\Rules\PhotoExtractionRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;

#[Temperature(0)]
final class PhotoVisionAgent implements Agent, HasProviderOptions, HasStructuredOutput, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    public const PROMPT = 'Describe this car photo.';

    /**
     * @param  string|null  $model  a vision model of the active provider to use instead of its default
     */
    public function __construct(private readonly ?string $model = null) {}

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
            'caption' => $schema->string()->min(1)->max(PhotoExtractionRules::MAX_CAPTION_LENGTH)->required(),
            'tags' => $schema->array()
                ->items($schema->string()->min(1)->max(PhotoExtractionRules::MAX_TAG_LENGTH))
                ->min(0)
                ->max(PhotoExtractionRules::MAX_TAGS)
                ->required(),
            'dominant_colours' => $schema->array()
                ->items($schema->string()->enum(PhotoExtractionRules::COLOURS))
                ->min(1)
                ->max(PhotoExtractionRules::MAX_COLOURS)
                ->required(),
            'body_type' => $schema->string()->enum(PhotoExtractionRules::BODY_TYPES)->required(),
            'visible_damage' => $schema->string()->enum(PhotoExtractionRules::DAMAGE_LEVELS)->required(),
            'people_count' => $schema->integer()->min(0)->max(PhotoExtractionRules::MAX_PEOPLE)->required(),
        ];
    }

    /**
     * Sends one stored photo, read from a disk path of "listing_photos", never from request input.
     */
    public function describe(string $path, string $disk = 'public'): StructuredAgentResponse
    {
        /** @var StructuredAgentResponse */
        return $this->prompt(self::PROMPT, [Image::fromStorage($path, $disk)]);
    }

    protected function feature(): string
    {
        return 'photo-vision';
    }

    public function modelName(): string
    {
        if (! $this->hasProviderOverride() && $this->model !== null) {
            return $this->model;
        }

        return app(AiProviders::class)->configured($this->feature(), $this->providerOverride)->model;
    }

    public function model(): string
    {
        if (! $this->hasProviderOverride() && $this->model !== null) {
            app(AiProviders::class)->forFeature($this->feature(), $this->providerOverride);

            return $this->model;
        }

        return app(AiProviders::class)->forFeature($this->feature(), $this->providerOverride)->model;
    }

    /**
     * The prompt's name and version, read from its file name: "photo-vision.v1.md" is "photo-vision@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.photo_vision');
    }
}
