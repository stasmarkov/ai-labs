<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\DuplicateJudgeMember;
use App\Rules\PhotoBestPickRules;
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
final class PhotoBestPickAgent implements Agent, HasProviderOptions, HasStructuredOutput, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    /** At most this many images go in one call. */
    public const MAX_PHOTOS = 6;

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
            'best_photo_id' => $schema->integer()->required(),
            'reasons' => $schema->array()
                ->items($schema->string()->min(1)->max(PhotoBestPickRules::MAX_REASON_LENGTH))
                ->min(1)
                ->max(PhotoBestPickRules::MAX_REASONS)
                ->required(),
            'issues' => $schema->array()
                ->items($schema->object([
                    'photo_id' => $schema->integer()->required(),
                    'problem' => $schema->string()->enum(PhotoBestPickRules::PROBLEMS)->required(),
                ])->withoutAdditionalProperties())
                ->min(0)
                ->max(self::MAX_PHOTOS - 1)
                ->required(),
        ];
    }

    /**
     * Sends every photo of the group in one call, the images in the order of their "Photo id <id>:" lines. The files are
     * read from disk paths of "listing_photos", never from request input.
     *
     * @param  list<DuplicateJudgeMember>  $members
     */
    public function judge(array $members, string $disk = 'public'): StructuredAgentResponse
    {
        $lines = array_map(fn (DuplicateJudgeMember $member): string => "Photo id {$member->photoId}:", $members);
        $prompt = "Choose the best of these photos. They are attached in this order:\n".implode("\n", $lines);

        /** @var StructuredAgentResponse */
        return $this->prompt($prompt, array_map(fn (DuplicateJudgeMember $member): Image => Image::fromStorage($member->path, $disk), $members));
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
     * The prompt's name and version, read from its file name: "photo-best-pick.v1.md" is "photo-best-pick@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.photo_best_pick');
    }
}
