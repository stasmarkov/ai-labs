<?php

declare(strict_types=1);

use App\Ai\AiProviders;
use App\Ai\PhotoVisionAgent;
use App\Exceptions\ProviderNotConfiguredException;
use App\Rules\PhotoExtractionRules;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Files\Image;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * @return array<string, mixed>
 */
function validExtraction(): array
{
    return [
        'caption' => 'Red hatchback parked on a street.',
        'tags' => ['alloy wheels', 'street'],
        'dominant_colours' => ['red'],
        'body_type' => 'hatchback',
        'visible_damage' => 'none',
        'people_count' => 0,
    ];
}

it('AC-3 sends the stored photo with the prompt and returns the fake output', function (): void {
    PhotoVisionAgent::fake([validExtraction()]);

    $response = (new PhotoVisionAgent('gemma-test'))->describe('photos/1/1.jpg');

    expect($response->toArray())->toMatchArray(validExtraction())
        ->and(Validator::make($response->toArray(), PhotoExtractionRules::rules())->passes())->toBeTrue();

    PhotoVisionAgent::assertPromptedTimes(1);
    PhotoVisionAgent::assertPrompted(function ($prompt): bool {
        $image = $prompt->attachments->first();

        return $prompt->attachments->count() === 1
            && $image instanceof Image
            && $image->path === 'photos/1/1.jpg'
            && $image->disk === 'public'
            && $prompt->model === 'gemma-test';
    });
});

it('AC-3 declares every key of the extraction as required with its enums and limits', function (): void {
    $schema = (new PhotoVisionAgent)->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toBe(['caption', 'tags', 'dominant_colours', 'body_type', 'visible_damage', 'people_count']);

    $caption = $schema['caption']->toArray();
    $tags = $schema['tags']->toArray();
    $colours = $schema['dominant_colours']->toArray();

    expect($caption['minLength'])->toBe(1)
        ->and($caption['maxLength'])->toBe(200)
        ->and($tags['maxItems'])->toBe(8)
        ->and($tags['items']['maxLength'])->toBe(24)
        ->and($colours['minItems'])->toBe(1)
        ->and($colours['maxItems'])->toBe(3)
        ->and($colours['items']['enum'])->toBe(['black', 'white', 'grey', 'silver', 'red', 'blue', 'green', 'yellow', 'orange', 'brown', 'beige', 'gold', 'purple'])
        ->and($schema['body_type']->toArray()['enum'])->toBe(['hatchback', 'saloon', 'estate', 'SUV', 'MPV', 'coupe', 'convertible', 'pickup', 'van', 'unknown'])
        ->and($schema['visible_damage']->toArray()['enum'])->toBe(['none', 'minor', 'major', 'unknown'])
        ->and($schema['people_count']->toArray()['minimum'])->toBe(0)
        ->and($schema['people_count']->toArray()['maximum'])->toBe(20);
});

it('AC-3 rejects output that breaks a rule in PHP', function (array $change): void {
    $validator = Validator::make([...validExtraction(), ...$change], PhotoExtractionRules::rules());

    expect($validator->fails())->toBeTrue();
})->with([
    'empty caption' => [['caption' => '']],
    'long caption' => [['caption' => str_repeat('a', 201)]],
    'nine tags' => [['tags' => array_fill(0, 9, 'tag')]],
    'long tag' => [['tags' => [str_repeat('a', 25)]]],
    'upper-case tag' => [['tags' => ['Wheels']]],
    'no colour' => [['dominant_colours' => []]],
    'four colours' => [['dominant_colours' => ['red', 'blue', 'green', 'black']]],
    'unknown colour' => [['dominant_colours' => ['pink']]],
    'unknown body' => [['body_type' => 'limousine']],
    'unknown damage' => [['visible_damage' => 'total']],
    'too many people' => [['people_count' => 21]],
    'negative people' => [['people_count' => -1]],
    'text people' => [['people_count' => 'two']],
]);

it('AC-3 accepts an empty tag list and unknown body and damage', function (): void {
    $input = [...validExtraction(), 'tags' => [], 'body_type' => 'unknown', 'visible_damage' => 'unknown'];

    expect(Validator::make($input, PhotoExtractionRules::rules())->passes())->toBeTrue();
});

it('AC-3 reads the prompt file and reports photo-vision@v1', function (): void {
    $agent = new PhotoVisionAgent;

    expect($agent->promptVersion())->toBe('photo-vision@v1')
        ->and(file_exists(resource_path('prompts/photo-vision.v1.md')))->toBeTrue()
        ->and($agent->instructions())->toContain('dominant_colours')->toContain('people_count')->toContain('unknown');
});

it('AC-3 takes the provider and model from the photo-vision feature config', function (): void {
    config(['ai.features.photo-vision' => ['provider' => null, 'model' => null], 'ai.default' => 'fake-stream']);
    expect((new PhotoVisionAgent)->provider())->toBe('fake-stream');

    config(['ai.features.photo-vision' => ['provider' => 'ollama', 'model' => 'qwen3.5:9b']]);
    $agent = new PhotoVisionAgent;

    expect($agent->provider())->toBe('ollama')
        ->and($agent->model())->toBe('qwen3.5:9b')
        ->and($agent->modelName())->toBe('qwen3.5:9b')
        ->and((new PhotoVisionAgent('gemma4:12b'))->model())->toBe('gemma4:12b');
});

it('AC-3 defaults the photo-vision feature to Ollama with its vision model gemma4:12b', function (): void {
    $feature = require base_path('config/ai.php');

    expect($feature['features']['photo-vision'])->toBe(['provider' => 'ollama', 'model' => null])
        ->and($feature['providers']['ollama']['models']['vision']['default'])->toBe('gemma4:12b')
        ->and($feature['prompts']['photo_vision'])->toBe('photo-vision.v1');
});

it('MNC-2 a cloud provider set for photo-vision alone uses that provider\'s own model, never the Ollama id', function (): void {
    config(['ai.features.photo-vision.provider' => 'anthropic', 'ai.features.photo-vision.model' => null, 'ai.providers.anthropic.key' => 'k']);

    $choice = app(AiProviders::class)->configured('photo-vision');

    expect($choice->provider)->toBe('anthropic')
        ->and($choice->model)->toBe(config('ai.providers.anthropic.models.text.default'))
        ->and($choice->model)->not->toBe('gemma4:12b');
});

it('AC-3 refuses a cloud provider without a key for photo-vision', function (): void {
    config(['ai.features.photo-vision.provider' => 'openai', 'ai.providers.openai.key' => null]);

    expect(fn () => (new PhotoVisionAgent)->provider())->toThrow(ProviderNotConfiguredException::class);
});

it('MNC-2 keeps the resolver of the existing features and adds photo-vision as a new key', function (): void {
    config([
        'ai.default' => 'fake-stream',
        'ai.features.describe' => ['provider' => null, 'model' => null],
        'ai.features.nl-search' => ['provider' => null, 'model' => null],
    ]);
    $providers = app(AiProviders::class);

    expect($providers->forFeature('describe')->provider)->toBe('fake-stream')
        ->and($providers->forFeature('nl-search')->provider)->toBe('fake-stream')
        ->and($providers->forFeature('photo-vision')->provider)->toBe('ollama')
        ->and(fn () => $providers->forFeature('unknown-feature'))->toThrow(InvalidArgumentException::class);
});
