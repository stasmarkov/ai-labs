<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\Fuel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Temperature(0)]
final class ListingFilterAgent implements Agent, HasProviderOptions, HasStructuredOutput, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    /**
     * @param  string|null  $model  a text model of the active provider to use instead of its default
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
            'make' => $schema->string()->nullable()->required(),
            'model' => $schema->string()->nullable()->required(),
            'city' => $schema->string()->nullable()->required(),
            'fuel' => $schema->string()->enum([...array_column(Fuel::cases(), 'value'), null])->nullable()->required(),
            'min_year' => $schema->integer()->nullable()->required(),
            'max_price_eur' => $schema->integer()->nullable()->required(),
            'unsupported' => $schema->array()->items($schema->string())->required(),
        ];
    }

    /**
     * Its own short limit instead of the provider's streaming one: a slow model must not hold a PHP worker for minutes.
     */
    public function timeout(): int
    {
        return max(1, (int) config('ai.nl_search.timeout', 20));
    }

    public function providerName(): string
    {
        return (string) config('ai.default');
    }

    public function modelName(): string
    {
        return $this->model ?? (string) ($this->activeProviderEntry()['models']['text']['default'] ?? '');
    }

    /**
     * The prompt's name and version, read from its file name: "listing-filters.v1.md" is "listing-filters@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.listing_filters');
    }
}
