<?php

declare(strict_types=1);

use App\Ai\AiRunRecorder;
use App\Ai\SseProtocol;
use App\Contracts\AiRunRepositoryInterface;
use App\DTOs\AiRunDetail;
use App\DTOs\AiRunRecord;
use App\DTOs\DocsCacheRunCounts;
use App\Models\AiRun;
use App\Models\Dealer;
use App\Models\Listing;
use Carbon\CarbonImmutable;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamStart;

it('AC-6 a provider error event reaches the client once, masked', function (): void {
    $error = new Error('e1', 'overloaded_error', 'Upstream secret detail', false, time());
    $stream = new StreamableAgentResponse('inv-1', function () use ($error) {
        yield new StreamStart('s1', 'ollama', 'model', time());
        yield $error;

        throw new StreamErrorException($error);
    });

    $sent = (new SseProtocol(app(AiRunRecorder::class)))->response($stream);
    $body = '';
    ob_start(function (string $chunk) use (&$body): string {
        $body .= $chunk;

        return '';
    }, 1);
    $sent->sendContent();
    ob_end_clean();

    expect($body)->not->toContain('Upstream secret detail')
        ->and($body)->not->toContain('overloaded_error')
        ->and(substr_count($body, '"type":"error"'))->toBe(1)
        ->and($body)->toEndWith("data: [DONE]\n\n");
});

it('AC-5 a failed ai_runs insert is reported and the stream still ends with [DONE]', function (): void {
    $this->app->bind(AiRunRepositoryInterface::class, fn () => new class implements AiRunRepositoryInterface
    {
        public function save(AiRunRecord $record): void
        {
            throw new RuntimeException('database is gone');
        }

        public function recent(int $limit): array
        {
            return [];
        }

        public function findByInvocationId(string $invocationId): ?AiRunDetail
        {
            return null;
        }

        public function storedErrorMessage(string $invocationId): ?string
        {
            return null;
        }

        public function docsCacheRuns(?CarbonImmutable $since): DocsCacheRunCounts
        {
            return new DocsCacheRunCounts(0, [], []);
        }
    });
    $listing = Listing::factory()->for(Dealer::factory())->create();

    $body = $this->get('/listings/'.$listing->id.'/describe')->streamedContent();

    expect($body)->toEndWith("data: [DONE]\n\n")
        ->and($body)->not->toContain('"type":"error"')
        ->and(AiRun::count())->toBe(0);
});
