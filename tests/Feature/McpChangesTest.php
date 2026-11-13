<?php

declare(strict_types=1);

use App\Ai\AdminTools\AddTagTool;
use App\Ai\AdminTools\AdminWriteApplier;
use App\Ai\AdminTools\UpdateTitleTool;
use App\Contracts\AdminListingRepositoryInterface;
use App\Contracts\McpToolCallRepositoryInterface;
use App\Contracts\PendingChangeRepositoryInterface;
use App\DTOs\McpToolCallRecord;
use App\DTOs\NewPendingChange;
use App\DTOs\PendingChangeRecord;
use App\Models\Listing;
use App\Models\ListingTag;
use App\Models\McpToolCall;
use App\Models\PendingChange;
use App\Services\ChangeProposalService;
use App\Services\PendingChangeNonces;
use App\Services\PendingChangeStatusService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

function newTitleChange(?int $listingId = null, int $ttlMinutes = 30): NewPendingChange
{
    return new NewPendingChange(
        tool: 'updateTitle',
        arguments: ['id' => $listingId ?? Listing::factory()->create()->id, 'title' => 'A new title'],
        preview: ['new_title' => 'A new title'],
        expiresAt: now()->addMinutes($ttlMinutes)->toImmutable(),
    );
}

it('AC-3 stores a proposal as pending in the new tables', function (): void {
    expect(Schema::hasColumns('pending_changes', [
        'id', 'source', 'tool', 'arguments', 'preview', 'status', 'result', 'error', 'expires_at', 'decided_at', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('mcp_tool_calls', [
            'id', 'tool', 'arguments', 'result_summary', 'outcome', 'error', 'duration_ms', 'pending_change_id', 'created_at',
        ]))->toBeTrue();

    $change = app(PendingChangeRepositoryInterface::class)->create(newTitleChange());

    expect($change->id)->toBeUuid()
        ->and($change->status)->toBe('pending')
        ->and($change->source)->toBe('mcp')
        ->and($change->preview)->toBe(['new_title' => 'A new title'])
        ->and($change->result)->toBeNull()
        ->and($change->decidedAt)->toBeNull();
});

it('AC-3 logs a tool call and finds the call that proposed a change', function (): void {
    $change = app(PendingChangeRepositoryInterface::class)->create(newTitleChange());
    $calls = app(McpToolCallRepositoryInterface::class);

    $calls->record(new McpToolCallRecord('propose_title_change', ['title' => 'x'], ['change_id' => $change->id], 'proposed', null, 12, $change->id));
    $calls->record(new McpToolCallRecord('search_listings', ['query' => 'bmw'], ['total' => 3], 'ok', null, 4, null));

    expect(McpToolCall::query()->count())->toBe(2)
        ->and($calls->findByPendingChange($change->id)?->tool)->toBe('propose_title_change')
        ->and($calls->findByPendingChange($change->id)?->outcome)->toBe('proposed');
});

it('AC-3 rejects an outcome outside the allowed set', function (): void {
    expect(fn () => McpToolCall::factory()->create(['outcome' => 'bogus']))->toThrow(QueryException::class);
});

it('AC-5 marks a pending change past its expiry as expired when a decision is attempted', function (): void {
    $repository = app(PendingChangeRepositoryInterface::class);
    $change = $repository->create(newTitleChange());

    Carbon::setTestNow(now()->addMinutes(31));

    expect($repository->claim($change->id))->toBeFalse()
        ->and($repository->markExpired($change->id))->toBeTrue()
        ->and($repository->find($change->id)?->status)->toBe('expired')
        ->and($repository->markExpired($change->id))->toBeFalse();
});

it('AC-5 expires every overdue pending change and leaves the live ones', function (): void {
    $repository = app(PendingChangeRepositoryInterface::class);
    $old = $repository->create(newTitleChange());
    Carbon::setTestNow(now()->addMinutes(31));
    $live = $repository->create(newTitleChange());

    expect($repository->expireOverdue())->toBe(1)
        ->and($repository->find($old->id)?->status)->toBe('expired')
        ->and($repository->find($live->id)?->status)->toBe('pending');
});

it('AC-5 claims a pending change once with a conditional update', function (): void {
    $repository = app(PendingChangeRepositoryInterface::class);
    $change = $repository->create(newTitleChange());

    expect($repository->claim($change->id))->toBeTrue()
        ->and($repository->claim($change->id))->toBeFalse()
        ->and($repository->reject($change->id))->toBeFalse()
        ->and($repository->find($change->id)?->status)->toBe('applying');

    $repository->settle($change->id, 'confirmed', ['status' => 'applied'], null);

    $settled = $repository->find($change->id);
    expect($settled?->status)->toBe('confirmed')
        ->and($settled?->result)->toBe(['status' => 'applied'])
        ->and($settled?->decidedAt)->not->toBeNull();
});

it('AC-5 rejects a pending change once and then refuses a claim', function (): void {
    $repository = app(PendingChangeRepositoryInterface::class);
    $change = $repository->create(newTitleChange());

    expect($repository->reject($change->id))->toBeTrue()
        ->and($repository->reject($change->id))->toBeFalse()
        ->and($repository->claim($change->id))->toBeFalse()
        ->and($repository->find($change->id)?->status)->toBe('rejected');
});

it('AC-5 lists live pending changes newest first and the decided ones apart', function (): void {
    $repository = app(PendingChangeRepositoryInterface::class);
    $first = $repository->create(newTitleChange());
    Carbon::setTestNow(now()->addMinute());
    $second = $repository->create(newTitleChange());
    $rejected = $repository->create(newTitleChange());
    $repository->reject($rejected->id);

    expect(array_map(fn ($change) => $change->id, $repository->pending(50)))->toBe([$second->id, $first->id])
        ->and(array_map(fn ($change) => $change->id, $repository->recent(20)))->toBe([$rejected->id]);
});

it('AC-5 returns null for an unknown change', function (): void {
    expect(app(PendingChangeRepositoryInterface::class)->find((string) Str::uuid()))->toBeNull();
});

it('AC-5 applies a title through the shared applier and reports the outcome', function (): void {
    $listing = Listing::factory()->create(['title' => 'Old']);

    $outcome = app(AdminWriteApplier::class)->apply('updateTitle', ['id' => $listing->id, 'title' => 'Fresh title']);

    expect($outcome->outcome)->toBe('confirmed')
        ->and($outcome->error)->toBeNull()
        ->and($outcome->reply['status'])->toBe('applied')
        ->and($listing->fresh()->title)->toBe('Fresh title');
});

it('NFR-3 ends a change to a deleted listing as invalid, not as an exception', function (): void {
    $listing = Listing::factory()->create();
    $arguments = ['id' => $listing->id, 'title' => 'Fresh title'];
    $listing->delete();

    $outcome = app(AdminWriteApplier::class)->apply('updateTitle', $arguments);

    expect($outcome->outcome)->toBe('error')
        ->and($outcome->error)->toBe('invalid_at_confirm');
});

it('refuses a tool that is not a write tool', function (): void {
    $outcome = app(AdminWriteApplier::class)->apply('search_listings', []);

    expect($outcome->outcome)->toBe('error')
        ->and($outcome->error)->toBe('unknown_tool');
});

it('builds models from their factories', function (): void {
    expect(PendingChange::factory()->create()->status)->toBe('pending')
        ->and(McpToolCall::factory()->create()->outcome)->toBe('ok');
});

function mcpLocal(bool $local = true): void
{
    test()->withoutMiddleware(PreventRequestForgery::class);
    RateLimiter::clear('mcp-changes');
    app()->detectEnvironment(fn (): string => $local ? 'local' : 'testing');
    config(['ai.mcp.enabled' => true, 'ai.mcp.per_minute' => 30]);
}

/**
 * What a browser sends from the page itself.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function decisionHeaders(array $overrides = []): array
{
    return [...['Sec-Fetch-Site' => 'same-origin', 'Origin' => rtrim((string) config('app.url'), '/')], ...$overrides];
}

/**
 * Decides a change the way the page does: it opens the change page first, which puts the nonce into this session.
 *
 * @param  array<string, string>|null  $headers
 */
function decide(string $action, PendingChangeRecord|string $change, ?string $nonce = null, ?array $headers = null): TestResponse
{
    $id = is_string($change) ? $change : $change->id;

    if ($nonce === null && ! is_string($change)) {
        $nonce = test()->get("/ai/changes/{$id}")->viewData('page')['props']['change']['nonce'] ?? null;
    }

    return test()->postJson("/ai/changes/{$id}/{$action}", ['nonce' => $nonce ?? 'unissued'], $headers ?? decisionHeaders());
}

function storedChange(?Listing $listing = null, int $ttlMinutes = 30): PendingChangeRecord
{
    return app(PendingChangeRepositoryInterface::class)->create(newTitleChange(($listing ?? Listing::factory()->create())->id, $ttlMinutes));
}

it('AC-5 confirms a change once, writes the listing and answers 409 change_not_pending on a second confirm', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Old']);
    $change = storedChange($listing);

    decide('confirm', $change)
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.result.status', 'applied');

    expect($listing->fresh()->title)->toBe('A new title');

    $listing->update(['title' => 'Edited since']);

    decide('confirm', $change)
        ->assertStatus(409)
        ->assertJsonPath('code', 'change_not_pending');

    expect($listing->fresh()->title)->toBe('Edited since')
        ->and(app(PendingChangeStatusService::class)->describe($change->id)['status'])->toBe('confirmed');
});

it('AC-5 rejects a change without writing and refuses a later confirm', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Old']);
    $change = storedChange($listing);

    decide('reject', $change)->assertOk()->assertJsonPath('data.status', 'rejected');
    decide('confirm', $change)->assertStatus(409)->assertJsonPath('code', 'change_not_pending');
    decide('reject', $change)->assertStatus(409)->assertJsonPath('code', 'change_not_pending');

    expect($listing->fresh()->title)->toBe('Old');
});

it('AC-5 answers 409 for an expired change, marks it expired and writes nothing', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Old']);
    $change = storedChange($listing);
    Carbon::setTestNow(now()->addMinutes(31));

    decide('confirm', $change)->assertStatus(409)->assertJsonPath('code', 'change_not_pending');

    expect($listing->fresh()->title)->toBe('Old')
        ->and(app(PendingChangeRepositoryInterface::class)->find($change->id)?->status)->toBe('expired');
});

it('AC-5 answers 409 change_not_pending for an unknown change id and 404 for a malformed one', function (): void {
    mcpLocal();

    decide('confirm', (string) Str::uuid())->assertStatus(409)->assertJsonPath('code', 'change_not_pending');
    decide('reject', (string) Str::uuid())->assertStatus(409)->assertJsonPath('code', 'change_not_pending');
    $this->postJson('/ai/changes/not-a-uuid/confirm')->assertNotFound();
});

it('NFR-3 ends a change to a deleted listing as failed, not as an exception', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create();
    $change = storedChange($listing);
    $listing->delete();

    decide('confirm', $change)
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error', 'invalid_at_confirm');

    expect(app(PendingChangeStatusService::class)->describe($change->id))->toMatchArray(['status' => 'failed', 'error' => 'invalid_at_confirm']);
});

it('NFR-1 refuses a decision that is not marked same-origin and writes nothing', function (array $headers): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Old']);
    $change = storedChange($listing);
    $nonce = $this->get("/ai/changes/{$change->id}")->viewData('page')['props']['change']['nonce'];

    foreach (['confirm', 'reject'] as $action) {
        decide($action, $change, $nonce, $headers)->assertForbidden();
    }

    expect($listing->fresh()->title)->toBe('Old')
        ->and(app(PendingChangeRepositoryInterface::class)->find($change->id)?->status)->toBe('pending');

    decide('confirm', $change, $nonce)->assertOk()->assertJsonPath('data.status', 'confirmed');
})->with([
    'no Sec-Fetch-Site header' => [['Origin' => 'https://ai-lab.ddev.site']],
    'cross-site' => [['Sec-Fetch-Site' => 'cross-site']],
    'same-site' => [['Sec-Fetch-Site' => 'same-site']],
    'none (address bar)' => [['Sec-Fetch-Site' => 'none']],
    'another origin' => [['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'https://evil.example']],
    'a null origin' => [['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'null']],
]);

it('NFR-1 lets a same-origin request without an Origin header through', function (): void {
    mcpLocal();
    $change = storedChange();
    $nonce = $this->get("/ai/changes/{$change->id}")->viewData('page')['props']['change']['nonce'];

    decide('reject', $change, $nonce, ['Sec-Fetch-Site' => 'same-origin'])->assertOk();
});

it('NFR-1 answers 404 outside the local environment or with the MCP switch off', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Old']);
    $change = storedChange($listing);

    app()->detectEnvironment(fn (): string => 'production');
    $this->postJson("/ai/changes/{$change->id}/confirm", ['nonce' => 'x'], decisionHeaders())->assertNotFound();
    $this->get('/ai/changes')->assertNotFound();
    $this->get("/ai/changes/{$change->id}")->assertNotFound();

    mcpLocal();
    config(['ai.mcp.enabled' => false]);
    $this->postJson("/ai/changes/{$change->id}/confirm", ['nonce' => 'x'], decisionHeaders())->assertNotFound();
    $this->postJson("/ai/changes/{$change->id}/reject", ['nonce' => 'x'], decisionHeaders())->assertNotFound();

    expect($listing->fresh()->title)->toBe('Old');
});

it('AC-7 hides the change pages and the navigation entry while the MCP switch is off', function (): void {
    mcpLocal();
    $change = storedChange();
    $navigation = fn (): array => array_column(
        $this->get('/ai')->assertOk()->viewData('page')['props']['lab']['navigation'],
        'href',
    );

    config(['ai.mcp.enabled' => false]);
    $this->get('/ai/changes')->assertNotFound();
    $this->get("/ai/changes/{$change->id}")->assertNotFound();
    expect($navigation())->not->toContain('/ai/changes');

    config(['ai.mcp.enabled' => true]);
    $this->get('/ai/changes')->assertOk();
    expect($navigation())->toContain('/ai/changes');
});

it('NFR-1 throttles the decision endpoints', function (): void {
    mcpLocal();
    config(['ai.mcp.per_minute' => 2]);
    $change = storedChange();

    decide('reject', $change)->assertOk();
    decide('reject', $change)->assertStatus(409);
    decide('reject', $change)->assertStatus(429);
});

it('AC-6 change_status returns each status', function (): void {
    mcpLocal();
    $statuses = app(PendingChangeStatusService::class);
    $repository = app(PendingChangeRepositoryInterface::class);

    $pending = storedChange();
    $confirmed = storedChange();
    $rejected = storedChange();
    $expired = storedChange(ttlMinutes: -1);
    $deleted = Listing::factory()->create();
    $failed = storedChange($deleted);
    $deleted->delete();
    $storedBeforeAnyPage = $repository->find($expired->id)?->status;

    decide('confirm', $confirmed)->assertOk();
    decide('reject', $rejected)->assertOk();
    decide('confirm', $failed)->assertOk();

    expect($statuses->describe($pending->id)['status'])->toBe('pending')
        ->and($statuses->describe($confirmed->id))->toMatchArray(['status' => 'confirmed'])
        ->and($statuses->describe($rejected->id)['status'])->toBe('rejected')
        ->and($statuses->describe($expired->id)['status'])->toBe('expired')
        ->and($statuses->describe($failed->id)['status'])->toBe('failed')
        ->and($storedBeforeAnyPage)->toBe('pending');
});

it('AC-4 renders the changes list and a change page, and lists the page in the navigation', function (): void {
    mcpLocal();
    $pending = storedChange();
    $rejected = storedChange();
    decide('reject', $rejected)->assertOk();

    $list = $this->get('/ai/changes')->assertOk()->assertInertia(fn ($page) => $page->component('Lab/Changes'));
    $props = $list->viewData('page')['props'];

    expect(array_column($props['pending'], 'id'))->toBe([$pending->id])
        ->and(array_column($props['recent'], 'id'))->toBe([$rejected->id])
        ->and($props['change'])->toBeNull()
        ->and(array_column($props['lab']['navigation'], 'href'))->toContain('/ai/changes');

    $detail = $this->get("/ai/changes/{$pending->id}")->assertOk()->viewData('page')['props'];

    expect($detail['change']['id'])->toBe($pending->id)
        ->and($detail['change']['status'])->toBe('pending')
        ->and($detail['change']['preview'])->toBe(['new_title' => 'A new title'])
        ->and($detail['confirmUrl'])->toBe("/ai/changes/{$pending->id}/confirm");

    $this->get('/ai/changes/'.Str::uuid())->assertNotFound();
    $this->get('/ai/changes/not-a-uuid')->assertNotFound();
});

it('AC-4 shows the proposing tool call and the validation message of a failed change', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create();
    $proposed = storedChange($listing);
    app(McpToolCallRepositoryInterface::class)->record(new McpToolCallRecord(
        'propose_title_change', ['id' => $listing->id, 'title' => 'A new title'], ['change_id' => $proposed->id], 'proposed', null, 12, $proposed->id,
    ));
    $manual = storedChange();

    $detail = $this->get("/ai/changes/{$proposed->id}")->assertOk()->viewData('page')['props']['change'];

    expect($detail['proposed_by']['tool'])->toBe('propose_title_change')
        ->and($detail['proposed_by']['arguments'])->toBe(['id' => $listing->id, 'title' => 'A new title'])
        ->and($detail['proposed_by']['time'])->not->toBeNull()
        ->and($this->get("/ai/changes/{$manual->id}")->viewData('page')['props']['change']['proposed_by'])->toBeNull();

    $listing->delete();
    decide('confirm', $proposed)->assertOk()->assertJsonPath('data.status', 'failed');

    $failed = $this->get("/ai/changes/{$proposed->id}")->viewData('page')['props']['change'];

    expect($failed['status'])->toBe('failed')
        ->and($failed['failure'])->toContain('No listing with id');

    $bare = storedChange();
    PendingChange::query()->whereKey($bare->id)->update(['status' => 'failed', 'error' => 'invalid_at_confirm', 'result' => null]);

    expect($this->get("/ai/changes/{$bare->id}")->viewData('page')['props']['change']['failure'])->toBe('the change could not be applied');
});

it('NFR-1 puts the decision endpoints behind the web group and the CSRF token', function (): void {
    mcpLocal();
    $this->withMiddleware(PreventRequestForgery::class);
    $router = app('router');
    $groups = app(Kernel::class)->getMiddlewareGroups();

    foreach (['changes.confirm', 'changes.reject'] as $name) {
        $middleware = collect($router->gatherRouteMiddleware($router->getRoutes()->getByName($name)))
            ->flatMap(fn (string $entry): array => $groups[$entry] ?? [$entry])
            ->all();

        expect($router->getRoutes()->getByName($name)->gatherMiddleware())->toContain('web')
            ->and($middleware)->toContain(PreventRequestForgery::class);
    }

    $change = storedChange(Listing::factory()->create(['title' => 'Old']));

    decide('confirm', $change, null, [])->assertStatus(419);
    expect(app(PendingChangeRepositoryInterface::class)->find($change->id)?->status)->toBe('pending');

    $this->withSession(['_token' => 'tok']);
    $nonce = $this->get("/ai/changes/{$change->id}")->viewData('page')['props']['change']['nonce'];

    $this->postJson("/ai/changes/{$change->id}/confirm", ['nonce' => $nonce], decisionHeaders(['X-CSRF-TOKEN' => 'tok']))
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');
});

it('NFR-1 refuses a decision without the nonce the page was given', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Old']);
    $change = storedChange($listing);
    $other = storedChange();
    $nonce = $this->get("/ai/changes/{$change->id}")->viewData('page')['props']['change']['nonce'];
    $otherNonce = $this->get("/ai/changes/{$other->id}")->viewData('page')['props']['change']['nonce'];

    expect($nonce)->toHaveLength(32)->and($otherNonce)->not->toBe($nonce);

    $this->postJson("/ai/changes/{$change->id}/confirm", [], decisionHeaders())->assertStatus(422)->assertJsonValidationErrors('nonce');
    decide('confirm', $change, 'not-the-nonce')->assertForbidden();
    decide('confirm', $change, $otherNonce)->assertForbidden();
    decide('confirm', $change, '')->assertStatus(422);

    expect($listing->fresh()->title)->toBe('Old')
        ->and(app(PendingChangeRepositoryInterface::class)->find($change->id)?->status)->toBe('pending');

    decide('confirm', $change, $nonce)->assertOk()->assertJsonPath('data.status', 'confirmed');
});

it('NFR-1 refuses a nonce from another session, as a client that never opened the page has none', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Old']);
    $change = storedChange($listing);
    $nonce = $this->get("/ai/changes/{$change->id}")->viewData('page')['props']['change']['nonce'];

    $this->flushSession();

    decide('confirm', $change, $nonce)->assertForbidden();
    decide('reject', $change, $nonce)->assertForbidden();
    expect($listing->fresh()->title)->toBe('Old');
});

it('NFR-1 answers 409 for a change that is no longer pending before it looks at the nonce', function (): void {
    mcpLocal();
    $change = storedChange();
    decide('reject', $change)->assertOk();

    decide('confirm', $change, 'stale-nonce')->assertStatus(409)->assertJsonPath('code', 'change_not_pending');
});

it('NFR-1 gives each pending change a random nonce, only on the pages, and uses it once', function (): void {
    mcpLocal();
    $first = storedChange();
    $second = storedChange();
    $rejected = storedChange();
    decide('reject', $rejected)->assertOk();

    $props = $this->get('/ai/changes')->assertOk()->viewData('page')['props'];
    $nonces = array_column($props['pending'], 'nonce', 'id');

    expect($nonces)->toHaveKeys([$first->id, $second->id])
        ->and($nonces[$first->id])->toHaveLength(32)
        ->and($nonces[$first->id])->not->toBe($nonces[$second->id])
        ->and($props['recent'][0])->not->toHaveKey('nonce')
        ->and($this->get("/ai/changes/{$first->id}")->viewData('page')['props']['change']['nonce'])->toBe($nonces[$first->id])
        ->and($this->get("/ai/changes/{$rejected->id}")->viewData('page')['props']['change'])->not->toHaveKey('nonce');

    $service = app(PendingChangeNonces::class);
    expect($service->consume($first->id, 'wrong'))->toBeFalse()
        ->and($service->consume($first->id, $nonces[$first->id]))->toBeTrue()
        ->and($service->consume($first->id, $nonces[$first->id]))->toBeFalse()
        ->and($service->consume($second->id, $nonces[$first->id]))->toBeFalse();
});

it('NFR-1 never puts a nonce into the MCP tool results or the confirm response', function (): void {
    mcpLocal();
    $change = storedChange();
    $nonce = $this->get("/ai/changes/{$change->id}")->viewData('page')['props']['change']['nonce'];

    $body = decide('confirm', $change, $nonce)->assertOk()->getContent();

    expect($body)->not->toContain($nonce);
});

it('NFR-3 ends a change as failed, never stuck applying, when the write throws', function (): void {
    mcpLocal();
    $change = storedChange();
    $nonce = $this->get("/ai/changes/{$change->id}")->viewData('page')['props']['change']['nonce'];
    $this->mock(AdminListingRepositoryInterface::class, function ($mock): void {
        $mock->shouldReceive('find')->andThrow(new RuntimeException('connection to 10.0.0.5 refused'));
        $mock->shouldReceive('findMany')->andReturn([]);
    });

    $response = decide('confirm', $change, $nonce)->assertOk()->assertJsonPath('data.status', 'failed');

    expect($response->getContent())->not->toContain('RuntimeException')->not->toContain('10.0.0.5')
        ->and(PendingChange::query()->find($change->id))->status->toBe('failed')
        ->and(app(PendingChangeStatusService::class)->describe($change->id))->toMatchArray(['status' => 'failed', 'error' => 'the change could not be applied']);
});

it('NFR-3 treats an applying change older than two minutes as failed and stores that', function (): void {
    mcpLocal();
    $stuck = PendingChange::factory()->create(['status' => 'applying']);
    $fresh = PendingChange::factory()->create(['status' => 'applying']);
    Carbon::setTestNow(now()->addMinutes(3));
    PendingChange::query()->whereKey($fresh->id)->update(['updated_at' => now()->subMinute()]);
    $statuses = app(PendingChangeStatusService::class);

    expect($statuses->describe($fresh->id)['status'])->toBe('pending')
        ->and($statuses->describe($stuck->id))->toMatchArray(['status' => 'failed', 'error' => 'the change could not be applied'])
        ->and($stuck->fresh())->status->toBe('failed')
        ->and($stuck->fresh()->decided_at)->not->toBeNull();

    $other = PendingChange::factory()->create(['status' => 'applying']);
    Carbon::setTestNow(now()->addMinutes(3));
    $props = $this->get('/ai/changes')->assertOk()->viewData('page')['props'];

    expect($other->fresh()->status)->toBe('failed')
        ->and(array_column($props['recent'], 'status', 'id'))->toMatchArray([$other->id => 'failed', $stuck->id => 'failed']);
});

it('NFR-3 reports a generic message, not the exception class, when the write fails unexpectedly', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Old']);
    $change = storedChange($listing);
    $nonce = $this->get("/ai/changes/{$change->id}")->viewData('page')['props']['change']['nonce'];
    Listing::updating(function (): never {
        throw new RuntimeException('SQLSTATE secret detail');
    });

    $response = decide('confirm', $change, $nonce)->assertOk()->assertJsonPath('data.status', 'failed');

    expect($response->json('data.error'))->toBe('the change could not be applied')
        ->and($response->json('data.failure'))->toBe('the change could not be applied')
        ->and($response->getContent())->not->toContain('RuntimeException')->not->toContain('secret')
        ->and(PendingChange::query()->find($change->id)->error)->toBe('the change could not be applied')
        ->and(app(PendingChangeStatusService::class)->describe($change->id)['error'])->toBe('the change could not be applied')
        ->and($listing->fresh()->title)->toBe('Old');

    Listing::flushEventListeners();
});

it('AC-4 shows the current title next to the proposal, and the old value in the applied result', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create(['title' => 'Title when proposed']);
    $proposal = app(ChangeProposalService::class)->propose(app(UpdateTitleTool::class), ['id' => $listing->id, 'title' => 'Proposed title']);
    $listing->update(['title' => 'Edited since the proposal']);

    $change = $this->get("/ai/changes/{$proposal->id}")->viewData('page')['props']['change'];

    expect($change['preview']['old_title'])->toBe('Title when proposed')
        ->and($change['current'])->toBe(['title' => 'Edited since the proposal', 'tags' => [], 'tags_total' => 0]);

    $row = $this->get('/ai/changes')->viewData('page')['props']['pending'][0];
    expect($row['current']['title'])->toBe('Edited since the proposal');

    $this->flushSession();
    decide('confirm', $proposal->id, $change['nonce'])->assertForbidden();
    decide('confirm', $proposal->id, $this->get("/ai/changes/{$proposal->id}")->viewData('page')['props']['change']['nonce'])
        ->assertOk()
        ->assertJsonPath('data.result.old_title', 'Edited since the proposal')
        ->assertJsonPath('data.result.new_title', 'Proposed title');
});

it('AC-4 shows the current tags of a listing, capped, beside a tag proposal', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create();

    foreach (range(1, 30) as $number) {
        ListingTag::create(['listing_id' => $listing->id, 'tag' => 'tag-'.$number]);
    }

    $proposal = app(ChangeProposalService::class)->propose(app(AddTagTool::class), ['id' => $listing->id, 'tag' => 'brand-new']);
    $change = $this->get("/ai/changes/{$proposal->id}")->viewData('page')['props']['change'];

    expect($change['current']['tags'])->toHaveCount(20)
        ->and($change['current']['tags_total'])->toBe(30)
        ->and($change['preview']['tags'])->toHaveCount(20)
        ->and($change['preview']['tags_total'])->toBe(30);
});

it('AC-4 sends slim list rows with the MCP tool name and a constant number of queries', function (): void {
    mcpLocal();
    $propose = function (int $count): void {
        foreach (range(1, $count) as $number) {
            $listing = Listing::factory()->create(['title' => null]);
            $proposal = app(ChangeProposalService::class)->propose(app(UpdateTitleTool::class), ['id' => $listing->id, 'title' => 'Proposed title '.$number]);
            app(McpToolCallRepositoryInterface::class)->record(new McpToolCallRecord('propose_title_change', ['id' => $listing->id], null, 'proposed', null, 3, $proposal->id));
        }
    };
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/ai/changes')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $propose(2);
    $queries();
    $few = $queries();
    $propose(6);
    $many = $queries();
    $props = $this->get('/ai/changes')->viewData('page')['props'];

    expect($props['pending'])->toHaveCount(8)
        ->and($many)->toBe($few);

    $row = $props['pending'][0];
    expect(array_keys($row))->toEqualCanonicalizing(['id', 'tool', 'status', 'listing', 'summary', 'current', 'expires_at', 'nonce'])
        ->and($row['tool'])->toBe('propose_title_change')
        ->and($row['listing'])->toHaveKeys(['id', 'make', 'model', 'year'])
        ->and($row['summary'])->toMatchArray(['old_title' => null])
        ->and($row['summary']['new_title'])->toStartWith('Proposed title');
});

it('AC-4 names the propose tool in the list for a tag change too', function (): void {
    mcpLocal();
    $listing = Listing::factory()->create();
    app(ChangeProposalService::class)->propose(app(AddTagTool::class), ['id' => $listing->id, 'tag' => 'family-car']);

    $row = $this->get('/ai/changes')->viewData('page')['props']['pending'][0];

    expect($row['tool'])->toBe('propose_tag')
        ->and($row['summary'])->toMatchArray(['tag' => 'family-car', 'already_tagged' => false]);
});
