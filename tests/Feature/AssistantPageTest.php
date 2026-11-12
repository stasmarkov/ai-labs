<?php

declare(strict_types=1);

use App\Http\Requests\AssistantAskRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

it('AC-5 the assistant page gets the ask endpoint, the run lookup, the limits and the example questions', function (): void {
    $this->get('/ai/assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Assistant')
            ->where('askUrl', '/api/assistant/ask')
            ->where('runUrl', '/ai/runs/RUN_ID')
            ->where('idPlaceholder', 'RUN_ID')
            ->where('guideHref', '/ai/guides/assistant')
            ->where('maxQuestionLength', AssistantAskRequest::MAX_QUESTION_LENGTH)
            ->where('maxTurnLength', AssistantAskRequest::MAX_TURN_LENGTH)
            ->where('maxHistoryTurns', (int) config('ai.docs.chat.max_history_turns'))
            ->has('howItWorks')
            ->has('enabledTextProviders')
            ->has('examples', 4));
});

it('AC-5 the example questions are plain non-empty questions within the question limit', function (): void {
    $examples = $this->get('/ai/assistant')->viewData('page')['props']['examples'];

    expect($examples)->not->toBeEmpty();

    foreach ($examples as $example) {
        expect($example)->toBeString()->not->toBeEmpty()
            ->and(mb_strlen($example))->toBeLessThanOrEqual(AssistantAskRequest::MAX_QUESTION_LENGTH);
    }
});

it('AC-5 the assistant is in the navigation, on the overview and on the home page as a ready capability with a guide', function (): void {
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);

    $navigation = $this->get('/ai/assistant')->viewData('page')['props']['lab']['navigation'];

    expect(array_column($navigation, 'href'))->toContain('/ai/assistant');

    $overview = $this->get('/ai')->viewData('page')['props'];
    $keys = array_column($overview['capabilities'], 'key');

    expect($keys)->toContain('assistant');

    $capability = $overview['capabilities'][array_search('assistant', $keys, true)];

    expect($capability['route'])->toBe('/ai/assistant')
        ->and($capability['title'])->toBe('Assistant')
        ->and($capability['status'])->toBe('ready')
        ->and($capability['guideKey'])->toBe('assistant');

    $this->get('/ai/guides/assistant')->assertOk();

    expect(array_column($this->get('/')->viewData('page')['props']['capabilities'], 'key'))->toContain('assistant');
});
