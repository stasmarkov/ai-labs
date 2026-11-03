<?php

declare(strict_types=1);

use App\Ai\CarDescriptionAgent;
use App\Ai\UsesActiveProvider;
use Laravel\Ai\Streaming\Events\TextDelta;

use function Laravel\Ai\agent;

it('MNC-1 fake-stream streams the configured canned text word by word', function (): void {
    config(['ai.providers.fake-stream.text' => 'Install it with composer [1].']);

    $deltas = [];
    foreach (agent(instructions: 'Answer.')->stream("Car: 2019 Land Rover Defender\nAsk") as $event) {
        if ($event instanceof TextDelta) {
            $deltas[] = $event->delta;
        }
    }

    expect($deltas)->toBe(['Install', ' it', ' with', ' composer', ' [1].']);
});

it('MNC-1 fake-stream keeps the filler when no canned text is configured', function (): void {
    config(['ai.providers.fake-stream.text' => null, 'ai.providers.fake-stream.tokens' => 6]);

    $text = TextDelta::combine(iterator_to_array(agent(instructions: 'Describe.')->stream('Car: 2019 Defender'), false));

    expect($text)->toStartWith('2019 Defender in excellent');
});

it('MNC-1 the describe agent reads its provider entry through the shared trait', function (): void {
    config(['ai.providers.fake-stream.max_tokens' => 77, 'ai.providers.fake-stream.timeout' => 12]);

    $agent = new CarDescriptionAgent;

    expect($agent->maxTokens())->toBe(77)
        ->and($agent->timeout())->toBe(12)
        ->and(class_uses($agent))->toHaveKey(UsesActiveProvider::class);
});
