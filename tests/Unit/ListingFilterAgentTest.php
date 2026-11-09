<?php

declare(strict_types=1);

use App\Ai\ListingFilterAgent;
use App\Models\AiRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('AC-4 declares the six filter fields as nullable and unsupported as a required list', function (): void {
    $schema = (new ListingFilterAgent)->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toBe(['make', 'model', 'city', 'fuel', 'min_year', 'max_price_eur', 'unsupported']);

    $fuel = $schema['fuel']->toArray();
    expect($fuel['enum'])->toBe(['petrol', 'diesel', 'hybrid', 'electric', 'lpg', null])
        ->and($fuel['type'])->toBe(['string', 'null'])
        ->and($schema['unsupported']->toArray()['type'])->toBe('array')
        ->and($schema['unsupported']->toArray()['items']['type'])->toBe('string');
});

it('AC-4 tells the model to put what the filters cannot express into unsupported', function (): void {
    $instructions = (new ListingFilterAgent)->instructions();

    expect($instructions)->toContain('unsupported')
        ->toContain('petrol')
        ->toContain('lpg')
        ->toContain('20000')
        ->toContain('2019');
});

it('AC-6 reports the prompt version listing-filters@v1', function (): void {
    expect((new ListingFilterAgent)->promptVersion())->toBe('listing-filters@v1')
        ->and(file_exists(resource_path('prompts/listing-filters.v1.md')))->toBeTrue();
});

it('AC-6 uses the active provider default model and lets a caller override it', function (): void {
    config(['ai.default' => 'ollama', 'ai.providers.ollama.models.text.default' => 'qwen-default']);

    expect((new ListingFilterAgent)->modelName())->toBe('qwen-default')
        ->and((new ListingFilterAgent('gemma-test'))->modelName())->toBe('gemma-test')
        ->and((new ListingFilterAgent)->providerName())->toBe('ollama');
});

it('AC-6 stores whether the output failed validation on the ai_runs row', function (): void {
    expect(Schema::hasColumn('ai_runs', 'validation_failed'))->toBeTrue();

    $run = AiRun::create([
        'invocation_id' => (string) fake()->uuid(),
        'agent' => 'ListingFilterAgent',
        'provider' => 'ollama',
        'model' => 'm',
        'prompt_version' => 'listing-filters@v1',
        'latency_ms' => 5,
        'status' => 'completed',
        'validation_failed' => true,
    ]);

    expect($run->fresh()->validation_failed)->toBeTrue();
});
