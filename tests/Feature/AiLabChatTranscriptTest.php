<?php

declare(strict_types=1);

use App\Http\Requests\DocsAskRequest;
use Inertia\Testing\AssertableInertia as Assert;

function chatPageSource(): string
{
    return file_get_contents(resource_path('js/ai/Pages/Lab/Chat.vue'));
}

it('AC-7 the chat page gets the history cap so it sends only the last turns', function (): void {
    $this->get('/ai/chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('maxHistoryTurns', (int) config('ai.docs.chat.max_history_turns')));
});

it('AC-7 the chat page leaves out an exchange whose answer exceeds the server turn limit', function (): void {
    $this->get('/ai/chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('maxTurnLength', DocsAskRequest::MAX_TURN_LENGTH));

    expect(chatPageSource())
        ->toContain('maxTurnLength: number')
        ->toContain('<= props.maxTurnLength');
});

it('AC-7 the chat page keeps a transcript with per-turn sources, citations, cached mark, refusal and run', function (): void {
    expect(chatPageSource())
        ->toContain('v-for="turn in turns"')
        ->toContain('turn.sources')
        ->toContain('turn.citations')
        ->toContain('turn.cacheHit')
        ->toContain('turn.refusal')
        ->toContain('turn.noContext')
        ->toContain('turn.run')
        ->toContain('<details')
        ->toContain('chat-source-${turn.id}-${n}')
        ->toContain('New chat');
});

it('AC-7 the chat page sends the last completed exchanges as history and never a stopped or refused answer', function (): void {
    expect(chatPageSource())
        ->toContain('history: history()')
        ->toContain('props.maxHistoryTurns')
        ->toContain("turn.outcome === 'done'")
        ->toContain('turn.refusal === null')
        ->toContain('turn.noContext === null')
        ->toContain("role: 'assistant'");
});

it('AC-3 the chat page marks a stopped answer Stopped and leaves it out of the history', function (): void {
    expect(chatPageSource())
        ->toContain("turn.outcome === 'stopped'")
        ->toContain('data-state="stopped"')
        ->toContain('Stopped')
        ->not->toContain('may not have logged');
});

it('NFR-3 the chat page has one aria-live region, on the newest answer only', function (): void {
    $source = chatPageSource();

    expect(substr_count($source, 'aria-live'))->toBe(1)
        ->and($source)->toContain('isNewest(turn)')
        // role="alert" is a live region too: inside the turn loop only the newest turn's refusal and error may have it,
        // so the one literal role="alert" left is the question length notice outside the transcript
        ->and(substr_count($source, ":role=\"isNewest(turn) ? 'alert' : undefined\""))->toBe(2)
        ->and(substr_count($source, ' role="alert"'))->toBe(1);
});
