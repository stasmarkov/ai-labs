<?php

declare(strict_types=1);

use App\DTOs\PlaygroundTarget;
use App\Services\PlaygroundCloudWeight;
use Tests\TestCase;

uses(TestCase::class);

it('NFR-1 counts the targets of a run that are on a cloud provider', function (): void {
    config(['ai.providers.anthropic.key' => 'sk-ant-test-key-value', 'ai.providers.openai.key' => null]);

    $weight = app(PlaygroundCloudWeight::class);
    $local = [new PlaygroundTarget('fake-instant', 'fake-instant-1'), new PlaygroundTarget('ollama', 'qwen3.5:9b')];

    expect($weight->cloudTargets($local))->toBe(0)
        ->and($weight->cloudTargets([new PlaygroundTarget('anthropic', 'claude-x'), ...$local]))->toBe(1)
        ->and($weight->cloudTargets([new PlaygroundTarget('anthropic', 'claude-x'), new PlaygroundTarget('anthropic', 'claude-y')]))->toBe(2);
});

it('NFR-1 counts a provider that is unknown or not enabled as cloud, so a refused request cannot slip under the budget', function (): void {
    config(['ai.providers.openai.key' => null]);

    expect(app(PlaygroundCloudWeight::class)->cloudTargets([new PlaygroundTarget('openai', 'gpt-5'), new PlaygroundTarget('nope', 'x')]))->toBe(2);
});
