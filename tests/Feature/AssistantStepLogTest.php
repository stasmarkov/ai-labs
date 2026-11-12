<?php

declare(strict_types=1);

use App\Ai\AiRunRecorder;
use App\Ai\BuyerAssistantAgent;
use App\Ai\Tools\SearchListings;
use App\Models\AiRun;
use App\Models\AiToolStep;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;

function startAssistantRun(string $invocationId = 'run-1'): AiRunRecorder
{
    $recorder = app(AiRunRecorder::class);
    $recorder->started($invocationId, 'BuyerAssistantAgent', 'fake-stream', 'tiny', 'buyer-assistant@v1');

    return $recorder;
}

/**
 * @param  array<string, mixed>  $arguments
 */
function toolInvoked(string $invocationId, array $arguments, string $result, float $time = 12.4): void
{
    event(new ToolInvoked($invocationId, 'call-'.uniqid(), new BuyerAssistantAgent, app(SearchListings::class), $arguments, $result, $time));
}

it('AC-4 logs each tool call as an ai_tool_steps row with a result summary', function (): void {
    $recorder = startAssistantRun();
    $result = json_encode(['total' => 31, 'shown' => 2, 'listings' => [
        ['id' => 7, 'make' => 'BMW', 'price_eur' => 9000],
        ['id' => 9, 'make' => 'BMW', 'price_eur' => 9500],
    ]]);
    toolInvoked('run-1', ['make' => 'BMW', 'limit' => 2], (string) $result);
    $recorder->completed('run-1', 10, 20);

    $run = AiRun::firstOrFail();
    $step = $run->steps()->firstOrFail();

    expect($run->tool_steps)->toBe(1)->and($run->tool_errors)->toBe(0)
        ->and($step->position)->toBe(1)
        ->and($step->tool)->toBe('SearchListings')
        ->and($step->arguments)->toEqual(['make' => 'BMW', 'limit' => 2])
        ->and($step->outcome)->toBe('ok')
        ->and($step->error)->toBeNull()
        ->and($step->duration_ms)->toBe(12)
        ->and($step->result_summary)->toEqual(['total' => 31, 'shown' => 2, 'listing_ids' => [7, 9]]);
});

it('AC-4 keeps the summary only, not the listings the tool returned', function (): void {
    $recorder = startAssistantRun();
    toolInvoked('run-1', ['city' => 'Berlin'], (string) json_encode(['total' => 1, 'shown' => 1, 'listings' => [['id' => 3, 'make' => 'Audi', 'city' => 'Berlin']]]));
    $recorder->completed('run-1', null, null);

    $stored = json_encode(AiToolStep::firstOrFail()->result_summary);

    expect($stored)->not->toContain('Audi')->and($stored)->not->toContain('Berlin');
});

it('AC-4 counts an invalid call as a tool error and numbers the calls in order', function (): void {
    $recorder = startAssistantRun();
    toolInvoked('run-1', ['min_year' => 'new'], (string) json_encode(['error' => 'invalid_arguments', 'final' => false, 'errors' => ['min_year' => ['The min year must be an integer.']]]));
    toolInvoked('run-1', ['min_year' => 2015], (string) json_encode(['total' => 0, 'shown' => 0, 'listings' => []]));
    $recorder->completed('run-1', null, null);

    $run = AiRun::firstOrFail();
    $steps = $run->steps;

    expect($run->tool_steps)->toBe(2)->and($run->tool_errors)->toBe(1)
        ->and($steps->pluck('position')->all())->toBe([1, 2])
        ->and($steps[0]->outcome)->toBe('error')->and($steps[0]->error)->toBe('invalid_arguments')
        ->and($steps[0]->result_summary)->toBe(['invalid_fields' => ['min_year']])
        ->and($steps[1]->outcome)->toBe('ok')->and($steps[1]->result_summary)->toEqual(['total' => 0, 'shown' => 0, 'listing_ids' => []]);
});

it('AC-4 logs a tool that threw as failed', function (): void {
    $recorder = startAssistantRun();
    event(new ToolFailed('run-1', 'call-1', new BuyerAssistantAgent, app(SearchListings::class), ['make' => 'BMW'], new RuntimeException('boom'), 3.0));
    $recorder->failed('run-1', new RuntimeException('provider down'));

    $run = AiRun::firstOrFail();
    $step = $run->steps()->firstOrFail();

    expect($run->status)->toBe('failed')->and($run->tool_steps)->toBe(1)->and($run->tool_errors)->toBe(1)
        ->and($step->outcome)->toBe('failed')->and($step->error)->toBe(RuntimeException::class)->and($step->result_summary)->toBeNull();
});

it('AC-4 logs the steps of a stopped run too', function (): void {
    $recorder = startAssistantRun();
    toolInvoked('run-1', [], (string) json_encode(['total' => 0, 'shown' => 0, 'listings' => []]));
    $recorder->stopped('run-1', 4);

    expect(AiRun::firstOrFail())->status->toBe('stopped')->tool_steps->toBe(1);
});

it('AC-4 leaves tool columns empty on a run without tool calls', function (): void {
    $recorder = startAssistantRun();
    $recorder->completed('run-1', 1, 1);

    expect(AiRun::firstOrFail())->tool_steps->toBeNull()->tool_errors->toBeNull()
        ->and(AiToolStep::count())->toBe(0);
});

it('AC-4 keeps the steps of one run apart from another', function (): void {
    $first = startAssistantRun('run-1');
    startAssistantRun('run-2');
    toolInvoked('run-1', ['make' => 'BMW'], (string) json_encode(['total' => 0, 'shown' => 0, 'listings' => []]));
    toolInvoked('run-2', ['make' => 'Audi'], (string) json_encode(['total' => 0, 'shown' => 0, 'listings' => []]));
    toolInvoked('run-2', ['make' => 'Opel'], (string) json_encode(['total' => 0, 'shown' => 0, 'listings' => []]));
    $first->completed('run-1', null, null);
    app(AiRunRecorder::class)->completed('run-2', null, null);

    expect(AiRun::where('invocation_id', 'run-1')->firstOrFail()->tool_steps)->toBe(1)
        ->and(AiRun::where('invocation_id', 'run-2')->firstOrFail()->tool_steps)->toBe(2);
});

it('AC-4 cuts long argument strings', function (): void {
    $recorder = startAssistantRun();
    toolInvoked('run-1', ['city' => str_repeat('x', 500)], (string) json_encode(['total' => 0, 'shown' => 0, 'listings' => []]));
    $recorder->completed('run-1', null, null);

    expect(mb_strlen(AiToolStep::firstOrFail()->arguments['city']))->toBe(200);
});

it('AC-4 removes the steps with their run', function (): void {
    $recorder = startAssistantRun();
    toolInvoked('run-1', [], (string) json_encode(['total' => 0, 'shown' => 0, 'listings' => []]));
    $recorder->completed('run-1', null, null);

    AiRun::query()->delete();

    expect(AiToolStep::count())->toBe(0);
});
