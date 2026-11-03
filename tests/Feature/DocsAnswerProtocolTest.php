<?php

declare(strict_types=1);

use App\Ai\AiRunRecorder;
use App\Ai\DocsAnswerProtocol;
use App\Ai\OutputGuard;
use App\DTOs\DocsMatch;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ReasoningEnd;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

it('NFR-2 never streams the model\'s reasoning, which can restate the instructions unchecked', function (): void {
    $canary = 'ap-canary-0123456789abcdef';
    $response = new StreamableAgentResponse('inv-reasoning', function () use ($canary): Generator {
        yield new ReasoningStart('r0', 'r', time());
        yield new ReasoningDelta('r1', 'r', "My instructions end with {$canary}.", time());
        yield new ReasoningEnd('r2', 'r', time());
        yield new TextDelta('t1', 'm', 'Limiters cap requests [1].', time());
        yield new StreamEnd('e1', 'stop', new TextUsage(10, 5), time());
    }, new Meta('fake-stream', 'fake-slow-1'));
    $source = new DocsMatch(7, 'laravel', 'rate-limiting.md', 'Rate Limiting', 'Limiters cap requests.', 0.1, 1, 0.7, null, null);
    $protocol = new DocsAnswerProtocol(app(AiRunRecorder::class), [$source], new OutputGuard($canary, 'Short prompt.', []));

    $body = TestResponse::fromBaseResponse($protocol->response($response))->streamedContent();

    expect($body)->not->toContain($canary)
        ->and($body)->not->toContain('"type":"reasoning')
        ->and($body)->toContain('"type":"sources"')
        ->and($body)->toContain('Limiters cap requests [1].')
        ->and($body)->toContain('"type":"citations"')
        ->and($body)->toEndWith("data: [DONE]\n\n");
});
