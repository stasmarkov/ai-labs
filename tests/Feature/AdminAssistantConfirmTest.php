<?php

declare(strict_types=1);

use App\Contracts\AdminListingRepositoryInterface;
use App\Exceptions\AdminAssistantConflictException;
use App\Http\Responses\JsonErrorRenderer;
use App\Models\AiRun;
use App\Models\AiToolStep;
use App\Models\Listing;
use App\Models\ListingTag;
use App\Repositories\EloquentAdminListingRepository;
use App\Services\AdminAssistantService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  list<array{0: string, 1: array<string, mixed>}>  $calls
 * @return array<string, mixed>
 */
function confirmToolAnswer(array $calls): array
{
    return [
        'choices' => [['message' => [
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => array_map(fn (array $call, int $index): array => [
                'id' => 'call_'.$index.'_'.$call[0],
                'type' => 'function',
                'function' => ['name' => $call[0], 'arguments' => json_encode((object) $call[1])],
            ], $calls, array_keys($calls)),
        ]]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
    ];
}

/**
 * @return array<string, mixed>
 */
function confirmTextAnswer(string $content): array
{
    return [
        'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        'usage' => ['prompt_tokens' => 150, 'completion_tokens' => 30],
    ];
}

/**
 * @param  list<array<string, mixed>>  $answers
 */
function confirmFakeModel(array $answers): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();

    $sequence = Http::sequence();

    foreach ($answers as $answer) {
        $sequence->push($answer);
    }

    Http::fake(['*/v1/chat/completions' => $sequence]);
}

/**
 * @return array<string, mixed>
 */
function confirmSentBody(int $index): array
{
    return Http::recorded()[$index][0]->data();
}

/**
 * Asks a question whose answer is one proposed write, and returns the conversation id and the action id.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{0: string, 1: string}
 */
function proposeTitle(int $listingId, string $title = 'Clean A4 avant'): array
{
    confirmFakeModel([confirmToolAnswer([['updateTitle', ['id' => $listingId, 'title' => $title]]])]);

    $reply = app(AdminAssistantService::class)->ask(null, 'Rename listing '.$listingId);

    return [$reply->conversationId, $reply->turn->pending->id];
}

function confirmConflict(Closure $action): AdminAssistantConflictException
{
    try {
        $action();
    } catch (AdminAssistantConflictException $exception) {
        return $exception;
    }

    throw new RuntimeException('No conflict was raised.');
}

it('AC-3 turns a proposed write into a pending action and writes nothing', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    confirmFakeModel([confirmToolAnswer([['updateTitle', ['id' => $listing->id, 'title' => 'Clean A4 avant']]])]);

    $reply = app(AdminAssistantService::class)->ask(null, 'Rename listing '.$listing->id);
    $pending = $reply->turn->pending;

    expect($pending)->not->toBeNull()
        ->and(strlen($pending->id))->toBe(32)
        ->and($pending->tool)->toBe('updateTitle')
        ->and($pending->preview)->toMatchArray(['id' => $listing->id, 'old_title' => null, 'new_title' => 'Clean A4 avant'])
        ->and($pending->expiresAt->diffInMinutes(now(), true))->toBeGreaterThan(9)->toBeLessThanOrEqual(10)
        ->and($reply->turn->steps[0]->outcome)->toBe('pending')
        ->and($reply->toArray()['pending_action']['action_id'])->toBe($pending->id)
        ->and($listing->fresh()->title)->toBeNull();

    Http::assertSentCount(1);
    expect(AiToolStep::query()->pluck('outcome')->all())->toBe(['pending']);
});

it('AC-3 and AC-4 keep one action at a time and defer the second write of the same answer', function (): void {
    $first = Listing::factory()->create(['title' => null]);
    $second = Listing::factory()->create(['title' => null]);
    confirmFakeModel([confirmToolAnswer([
        ['updateTitle', ['id' => $first->id, 'title' => 'First title']],
        ['updateTitle', ['id' => $second->id, 'title' => 'Second title']],
    ])]);

    $service = app(AdminAssistantService::class);
    $reply = $service->ask(null, 'Rename both');

    expect($reply->turn->pending->arguments['id'])->toBe($first->id)
        ->and(array_column(array_map(fn ($step): array => $step->toArray(), $reply->turn->steps), 'outcome'))->toBe(['pending', 'error'])
        ->and($reply->turn->steps[1]->error)->toBe('deferred')
        ->and($first->fresh()->title)->toBeNull()
        ->and($second->fresh()->title)->toBeNull();

    confirmFakeModel([
        confirmToolAnswer([['updateTitle', ['id' => $second->id, 'title' => 'Second title']]]),
        confirmTextAnswer('Both are done.'),
    ]);

    $afterFirst = $service->decide($reply->conversationId, $reply->turn->pending->id, true);

    expect($first->fresh()->title)->toBe('First title')
        ->and($second->fresh()->title)->toBeNull()
        ->and($afterFirst->turn->pending->arguments['id'])->toBe($second->id)
        ->and($afterFirst->turn->pending->id)->not->toBe($reply->turn->pending->id);

    $afterSecond = $service->decide($reply->conversationId, $afterFirst->turn->pending->id, true);

    expect($second->fresh()->title)->toBe('Second title')
        ->and($afterSecond->turn->answer)->toBe('Both are done.')
        ->and($afterSecond->turn->pending)->toBeNull();
});

it('AC-4 confirm applies the write once and lets the model continue', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    confirmFakeModel([confirmTextAnswer('The title is now Clean A4 avant.')]);

    $reply = app(AdminAssistantService::class)->decide($conversationId, $actionId, true);

    expect($listing->fresh()->title)->toBe('Clean A4 avant')
        ->and($reply->turn->answer)->toBe('The title is now Clean A4 avant.')
        ->and($reply->turn->pending)->toBeNull()
        ->and($reply->turn->steps)->toHaveCount(1)
        ->and($reply->turn->steps[0]->outcome)->toBe('confirmed')
        ->and($reply->turn->steps[0]->result)->toMatchArray(['id' => $listing->id, 'new_title' => 'Clean A4 avant', 'changed' => true]);

    $messages = confirmSentBody(0)['messages'];
    $tool = $messages[array_key_last($messages)];

    expect($tool['role'])->toBe('tool')
        ->and($tool['tool_call_id'])->toBe('call_0_updateTitle')
        ->and($tool['content'])->toStartWith('<tool_result>')->toContain('"status":"applied"')->toContain('"new_title":"Clean A4 avant"');

    $run = AiRun::query()->where('invocation_id', $reply->turn->steps[0]->runId)->firstOrFail();
    $outcomes = $run->steps()->orderBy('position')->pluck('outcome')->all();

    expect($outcomes)->toBe(['pending', 'confirmed'])
        ->and($run->tool_steps)->toBe(2)
        ->and($run->tool_errors)->toBe(0)
        ->and($run->steps()->orderBy('position')->pluck('position')->all())->toBe([1, 2]);
});

it('AC-4 a second confirm of the same action gives 409 and writes nothing', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    confirmFakeModel([confirmTextAnswer('Done.')]);
    $service = app(AdminAssistantService::class);
    $service->decide($conversationId, $actionId, true);
    $listing->update(['title' => 'Edited by hand']);

    $conflict = confirmConflict(fn () => $service->decide($conversationId, $actionId, true));

    expect($conflict->reason)->toBe(AdminAssistantConflictException::ACTION_NOT_PENDING)
        ->and($listing->fresh()->title)->toBe('Edited by hand')
        ->and(AiToolStep::query()->where('outcome', 'confirmed')->count())->toBe(1);

    Http::assertSentCount(1);
});

it('AC-4 reject writes nothing, tells the model and logs a rejected step', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    confirmFakeModel([confirmTextAnswer('Okay, I left it as it was.')]);
    $service = app(AdminAssistantService::class);

    $reply = $service->decide($conversationId, $actionId, false);

    expect($listing->fresh()->title)->toBeNull()
        ->and($reply->turn->answer)->toBe('Okay, I left it as it was.')
        ->and($reply->turn->steps[0]->outcome)->toBe('rejected')
        ->and(AiToolStep::query()->pluck('outcome')->all())->toBe(['pending', 'rejected']);

    $messages = confirmSentBody(0)['messages'];

    expect($messages[array_key_last($messages)]['content'])->toContain('"status":"rejected_by_developer"');

    expect(confirmConflict(fn () => $service->decide($conversationId, $actionId, true))->reason)->toBe(AdminAssistantConflictException::ACTION_NOT_PENDING)
        ->and($listing->fresh()->title)->toBeNull();
});

it('AC-5 an expired action gives 409, is dropped and writes nothing', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    Carbon::setTestNow(now()->addMinutes(11));
    $service = app(AdminAssistantService::class);

    $conflict = confirmConflict(fn () => $service->decide($conversationId, $actionId, true));

    expect($conflict->reason)->toBe(AdminAssistantConflictException::ACTION_NOT_PENDING)
        ->and($listing->fresh()->title)->toBeNull()
        ->and(AiToolStep::query()->orderBy('position')->get(['outcome', 'error'])->map(fn ($step) => [$step->outcome, $step->error])->all())->toBe([['pending', null], ['rejected', 'expired']])
        ->and(confirmConflict(fn () => $service->decide($conversationId, $actionId, true))->reason)->toBe(AdminAssistantConflictException::ACTION_NOT_PENDING);

    confirmFakeModel([confirmTextAnswer('Hello again.')]);

    expect($service->ask($conversationId, 'Hi')->turn->answer)->toBe('Hello again.');
});

it('AC-5 an expired action does not block the next question and the model is told it expired', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId] = proposeTitle($listing->id);
    Carbon::setTestNow(now()->addMinutes(11));
    confirmFakeModel([confirmTextAnswer('Fresh start.')]);

    $reply = app(AdminAssistantService::class)->ask($conversationId, 'Something else');

    $messages = confirmSentBody(0)['messages'];

    expect($reply->turn->answer)->toBe('Fresh start.')
        ->and(array_column($messages, 'role'))->toBe(['system', 'user', 'assistant', 'tool', 'user'])
        ->and($messages[3]['content'])->toContain('"status":"expired"')
        ->and($listing->fresh()->title)->toBeNull();
});

it('AC-5 an unknown action id gives 409 and keeps the real one', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    $service = app(AdminAssistantService::class);

    $conflict = confirmConflict(fn () => $service->decide($conversationId, str_repeat('a', 32), true));

    expect($conflict->reason)->toBe(AdminAssistantConflictException::ACTION_NOT_PENDING)
        ->and($listing->fresh()->title)->toBeNull();

    confirmFakeModel([confirmTextAnswer('Done.')]);
    $service->decide($conversationId, $actionId, true);

    expect($listing->fresh()->title)->toBe('Clean A4 avant');
});

it('AC-5 an action of another conversation gives 409 and an unknown conversation gives 404', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    $other = Listing::factory()->create(['title' => null]);
    [$conversationA, $actionA] = proposeTitle($listing->id);
    [$conversationB] = proposeTitle($other->id, 'Other title');
    $service = app(AdminAssistantService::class);

    expect(confirmConflict(fn () => $service->decide($conversationB, $actionA, true))->reason)->toBe(AdminAssistantConflictException::ACTION_NOT_PENDING)
        ->and(confirmConflict(fn () => $service->decide('not-a-conversation', $actionA, true))->reason)->toBe(AdminAssistantConflictException::CONVERSATION_NOT_FOUND)
        ->and(confirmConflict(fn () => $service->ask('not-a-conversation', 'Hi'))->reason)->toBe(AdminAssistantConflictException::CONVERSATION_NOT_FOUND)
        ->and($listing->fresh()->title)->toBeNull()
        ->and($other->fresh()->title)->toBeNull();
});

it('AC-5 a question while an action is pending gives 409 and sends nothing to the model', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    $service = app(AdminAssistantService::class);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake();

    $conflict = confirmConflict(fn () => $service->ask($conversationId, 'What is the weather?'));

    expect($conflict->reason)->toBe(AdminAssistantConflictException::ACTION_PENDING);
    Http::assertNothingSent();

    $response = JsonErrorRenderer::render($conflict, HttpRequest::create('/api/admin-assistant/ask', 'POST'));

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toMatchArray(['code' => 'action_pending']);

    confirmFakeModel([confirmTextAnswer('Done.')]);
    $service->decide($conversationId, $actionId, true);

    expect($listing->fresh()->title)->toBe('Clean A4 avant');
});

it('NFR-1 a yes typed in the chat writes nothing, with or without a pending action', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId] = proposeTitle($listing->id);
    $service = app(AdminAssistantService::class);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake();

    expect(confirmConflict(fn () => $service->ask($conversationId, 'yes'))->reason)->toBe(AdminAssistantConflictException::ACTION_PENDING)
        ->and($listing->fresh()->title)->toBeNull();

    $fresh = Listing::factory()->create(['title' => null]);
    confirmFakeModel([confirmToolAnswer([['updateTitle', ['id' => $fresh->id, 'title' => 'Sneaky title']]])]);

    $reply = $service->ask(null, 'yes, go ahead and confirm');

    expect($reply->turn->pending)->not->toBeNull()
        ->and($fresh->fresh()->title)->toBeNull();
});

it('AC-4 and NFR-3 a listing deleted before the confirm gives an invalid_at_confirm step and a not_applied message', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    $listing->delete();
    confirmFakeModel([confirmTextAnswer('That listing no longer exists.')]);

    $reply = app(AdminAssistantService::class)->decide($conversationId, $actionId, true);

    expect($reply->turn->steps[0]->outcome)->toBe('error')
        ->and($reply->turn->steps[0]->error)->toBe('invalid_at_confirm')
        ->and($reply->turn->steps[0]->result['details'])->toBe(['No listing with id '.$listing->id.'.'])
        ->and($reply->turn->answer)->toBe('That listing no longer exists.')
        ->and(AiToolStep::query()->orderBy('position')->pluck('outcome')->all())->toBe(['pending', 'error']);

    $messages = confirmSentBody(0)['messages'];

    expect($messages[array_key_last($messages)]['content'])->toContain('"status":"not_applied"')->toContain('"error":"invalid_at_confirm"');
});

it('NFR-3 a tag write validates again and a listing deleted between validate and write is a tool error', function (): void {
    $listing = Listing::factory()->create();
    confirmFakeModel([confirmToolAnswer([['addTag', ['id' => $listing->id, 'tag' => 'family-car']]])]);
    $service = app(AdminAssistantService::class);
    $reply = $service->ask(null, 'Tag it');
    $listing->delete();
    confirmFakeModel([confirmTextAnswer('Could not tag.')]);

    $decided = $service->decide($reply->conversationId, $reply->turn->pending->id, true);

    expect($decided->turn->steps[0]->outcome)->toBe('error')
        ->and(ListingTag::query()->count())->toBe(0);
});

it('NFR-3 an unexpected failure while writing is a failed step, rolls back and does not crash', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    $real = app(EloquentAdminListingRepository::class);
    $repository = Mockery::mock(AdminListingRepositoryInterface::class);
    $repository->shouldReceive('find')->andReturnUsing(fn (int $id) => $real->find($id));
    $repository->shouldReceive('transaction')->andReturnUsing(fn (Closure $callback) => $real->transaction($callback));
    $repository->shouldReceive('updateTitle')->andThrow(new RuntimeException('disk full'));
    app()->instance(AdminListingRepositoryInterface::class, $repository);
    app()->forgetInstance(AdminAssistantService::class);
    confirmFakeModel([confirmTextAnswer('The write failed.')]);

    $reply = rescue(fn () => app(AdminAssistantService::class)->decide($conversationId, $actionId, true), report: false);

    expect($reply->turn->steps[0]->outcome)->toBe('failed')
        ->and($reply->turn->steps[0]->result)->toBe(['status' => 'failed'])
        ->and($listing->fresh()->title)->toBeNull()
        ->and(AiRun::query()->whereNotNull('tool_errors')->value('tool_errors'))->toBe(1);
});

it('AC-4 keeps the decision when the model cannot continue afterwards', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    [$conversationId, $actionId] = proposeTitle($listing->id);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*/v1/chat/completions' => fn () => throw new ConnectionException('refused')]);
    $service = app(AdminAssistantService::class);

    $reply = $service->decide($conversationId, $actionId, true);

    expect($listing->fresh()->title)->toBe('Clean A4 avant')
        ->and($reply->turn->answer)->toStartWith('The decision was recorded, but the model could not continue')
        ->and($reply->turn->steps[0]->outcome)->toBe('confirmed')
        ->and(confirmConflict(fn () => $service->decide($conversationId, $actionId, true))->reason)->toBe(AdminAssistantConflictException::ACTION_NOT_PENDING);
});

it('AC-6 and NFR-2 the step and call caps span a confirmation pause', function (): void {
    $listing = Listing::factory()->create(['title' => null]);
    $read = fn (): array => confirmToolAnswer([['getListing', ['id' => $listing->id]]]);

    confirmFakeModel([
        $read(),
        $read(),
        $read(),
        confirmToolAnswer([['updateTitle', ['id' => $listing->id, 'title' => 'Clean A4 avant']]]),
    ]);

    $asked = app(AdminAssistantService::class)->ask(null, 'Check then rename listing '.$listing->id);

    expect($asked->turn->pending)->not->toBeNull()
        ->and(Http::recorded())->toHaveCount(4);

    confirmFakeModel([confirmToolAnswer([['getListing', ['id' => $listing->id]]])]);

    $reply = app(AdminAssistantService::class)->decide($asked->conversationId, $asked->turn->pending->id, true);

    $last = confirmSentBody(0);
    $stepLimit = collect($reply->turn->steps)->firstWhere('error', 'step_limit');

    expect($listing->fresh()->title)->toBe('Clean A4 avant')
        ->and($last['tool_choice'])->toBe('none')
        ->and($reply->turn->limitReached)->toBeTrue()
        ->and($stepLimit)->not->toBeNull()
        ->and($stepLimit->outcome)->toBe('error')
        ->and(AiToolStep::query()->where('error', 'step_limit')->where('outcome', 'error')->count())->toBe(1)
        ->and(AiToolStep::query()->where('tool', 'getListing')->where('outcome', 'ok')->count())->toBe(3)
        ->and(Http::recorded())->toHaveCount(1)
        ->and(AiRun::query()->count())->toBeLessThanOrEqual(6);
});
