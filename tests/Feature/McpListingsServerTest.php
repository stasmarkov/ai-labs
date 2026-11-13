<?php

declare(strict_types=1);

use App\Ai\AdminTools\AddTagTool;
use App\Ai\AdminTools\AdminResultCap;
use App\Ai\AdminTools\CountByTagTool;
use App\Ai\AdminTools\GetListingTool;
use App\Ai\AdminTools\SearchListingsTool;
use App\Ai\AdminTools\UpdateTitleTool;
use App\Contracts\AdminListingRepositoryInterface;
use App\Contracts\PendingChangeRepositoryInterface;
use App\Mcp\Servers\ListingsServer;
use App\Mcp\Tools\ChangeStatusTool;
use App\Mcp\Tools\CountByTagTool as McpCountByTagTool;
use App\Mcp\Tools\GetListingTool as McpGetListingTool;
use App\Mcp\Tools\ProposeTagTool;
use App\Mcp\Tools\ProposeTitleChangeTool;
use App\Mcp\Tools\SearchListingsTool as McpSearchListingsTool;
use App\Models\Listing;
use App\Models\ListingDocument;
use App\Models\ListingTag;
use App\Models\McpToolCall;
use App\Models\PendingChange;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @param  list<string>  $tags
 */
function taggedListing(array $tags = [], array $attributes = []): Listing
{
    $listing = Listing::factory()->create($attributes);

    foreach ($tags as $tag) {
        ListingTag::create(['listing_id' => $listing->id, 'tag' => $tag]);
    }

    return $listing;
}

it('AC-1 lists the read tools with descriptions, schemas and read-only annotations', function (): void {
    ListingsServer::tools()->assertRegistered([
        McpSearchListingsTool::class,
        McpGetListingTool::class,
        McpCountByTagTool::class,
        ChangeStatusTool::class,
    ]);

    foreach ([McpSearchListingsTool::class, McpGetListingTool::class, McpCountByTagTool::class, ChangeStatusTool::class] as $class) {
        $tool = app($class)->toArray();

        expect($tool['description'])->not->toBe('')
            ->and($tool['inputSchema']['type'])->toBe('object')
            ->and($tool['annotations']['readOnlyHint'])->toBeTrue();
    }

    expect(array_map(fn (string $class): string => app($class)->name(), [
        McpSearchListingsTool::class, McpGetListingTool::class, McpCountByTagTool::class, ChangeStatusTool::class,
    ]))->toBe(['search_listings', 'get_listing', 'count_by_tag', 'change_status']);
});

it('AC-1 lists all six tools, each with a description and an input schema, and the propose tools without annotations', function (): void {
    ListingsServer::tools()->assertRegistered([
        McpSearchListingsTool::class,
        McpGetListingTool::class,
        McpCountByTagTool::class,
        ChangeStatusTool::class,
        ProposeTitleChangeTool::class,
        ProposeTagTool::class,
    ]);

    $tools = array_map(fn (string $class): array => app($class)->toArray(), (new ReflectionClass(ListingsServer::class))->getProperty('tools')->getDefaultValue());

    expect($tools)->toHaveCount(6);

    foreach ($tools as $tool) {
        expect($tool['description'])->not->toBe('')
            ->and($tool['inputSchema']['type'])->toBe('object')
            ->and($tool['inputSchema']['properties'])->not->toBeEmpty();
    }

    foreach ([ProposeTitleChangeTool::class => ['id', 'title'], ProposeTagTool::class => ['id', 'tag']] as $class => $required) {
        $tool = app($class)->toArray();

        expect(array_keys($tool['annotations'] ?? []))->each->toBe('title')
            ->and($tool['inputSchema']['required'])->toBe($required);
    }

    expect(app(ProposeTitleChangeTool::class)->toArray()['inputSchema']['required'])->toBe(app(UpdateTitleTool::class)->parameters()['required'])
        ->and(app(ProposeTagTool::class)->toArray()['inputSchema']['required'])->toBe(app(AddTagTool::class)->parameters()['required']);
});

it('AC-1 never tells a model it cannot confirm, only that the developer must, in the browser', function (): void {
    $texts = [
        app(ProposeTitleChangeTool::class)->description(),
        app(ProposeTagTool::class)->description(),
        app(ChangeStatusTool::class)->description(),
        (string) (new ReflectionClass(ListingsServer::class))->getAttributes()[2]->newInstance()->value,
    ];

    foreach ($texts as $text) {
        expect($text)->not->toContain('cannot confirm')->and($text)->toContain('developer');
    }

    expect(app(ProposeTagTool::class)->description())->toContain('needs the developer in the browser');
});

it('AC-1 builds each input schema from the admin tool parameters', function (): void {
    $search = app(McpSearchListingsTool::class)->toArray()['inputSchema'];
    $expected = app(SearchListingsTool::class)->parameters();

    expect(array_keys((array) $search['properties']))->toBe(array_keys($expected['properties']))
        ->and($search['properties']['fuel']['enum'])->toBe($expected['properties']['fuel']['enum'])
        ->and($search['properties']['limit']['type'])->toBe('integer');

    expect(app(McpGetListingTool::class)->toArray()['inputSchema']['required'])->toBe(['id'])
        ->and(app(McpCountByTagTool::class)->toArray()['inputSchema']['required'])->toBe(['tag']);
});

it('AC-2 search_listings returns what the admin tool returns and logs an ok row', function (): void {
    taggedListing(['family-car'], ['make' => 'Volkswagen', 'model' => 'Golf']);
    taggedListing([], ['make' => 'Audi', 'model' => 'A4']);
    $arguments = ['make' => 'Volkswagen', 'limit' => 5];

    ListingsServer::tool(McpSearchListingsTool::class, $arguments)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee(AdminResultCap::encode(app(SearchListingsTool::class)->run($arguments)));

    $call = McpToolCall::query()->sole();

    expect($call->tool)->toBe('search_listings')
        ->and($call->outcome)->toBe('ok')
        ->and($call->error)->toBeNull()
        ->and($call->arguments)->toBe($arguments)
        ->and($call->result_summary['total'])->toBe(1)
        ->and($call->result_summary['shown'])->toBe(1)
        ->and($call->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and($call->pending_change_id)->toBeNull();
});

it('AC-2 get_listing and count_by_tag return what the admin tools return', function (): void {
    $listing = taggedListing(['family-car', 'diesel']);
    ListingDocument::create(['listing_id' => $listing->id, 'body_type' => 'sedan', 'description' => 'A fine car.']);

    ListingsServer::tool(McpGetListingTool::class, ['id' => $listing->id])
        ->assertHasNoErrors()
        ->assertSee(AdminResultCap::encode(app(GetListingTool::class)->run(['id' => $listing->id])));

    ListingsServer::tool(McpCountByTagTool::class, ['tag' => 'family-car'])
        ->assertHasNoErrors()
        ->assertSee(AdminResultCap::encode(app(CountByTagTool::class)->run(['tag' => 'family-car'])));

    expect(McpToolCall::query()->orderBy('id')->pluck('outcome')->all())->toBe(['ok', 'ok']);
});

it('AC-2 answers invalid arguments with an MCP error and logs an error row', function (): void {
    ListingsServer::tool(McpSearchListingsTool::class, ['limit' => 99, 'colour' => 'red'])
        ->assertHasErrors(['The colour argument is not supported.']);

    ListingsServer::tool(McpGetListingTool::class, ['id' => 0])
        ->assertHasErrors(['The id argument must be a positive integer.']);

    ListingsServer::tool(McpCountByTagTool::class, ['tag' => 'Not A Tag!'])
        ->assertHasErrors(['The tag argument must be']);

    $calls = McpToolCall::query()->orderBy('id')->get();

    expect($calls->pluck('outcome')->all())->toBe(['error', 'error', 'error'])
        ->and($calls[0]->error)->toContain('The colour argument is not supported.')
        ->and($calls[0]->result_summary)->toBeNull();
});

it('AC-2 reports a listing that does not exist as an error', function (): void {
    ListingsServer::tool(McpGetListingTool::class, ['id' => 999999])
        ->assertHasErrors(['No listing with id 999999.']);

    expect(McpToolCall::query()->sole()->outcome)->toBe('error');
});

it('NFR-2 caps rows, tags, description and size like the admin tools', function (): void {
    $listing = taggedListing(array_map(fn (int $number): string => "tag-{$number}", range(1, 30)));
    ListingDocument::create(['listing_id' => $listing->id, 'body_type' => 'sedan', 'description' => str_repeat('x', 900)]);
    Listing::factory()->count(12)->create();

    $response = ListingsServer::tool(McpGetListingTool::class, ['id' => $listing->id]);
    $expected = app(GetListingTool::class)->run(['id' => $listing->id]);

    $response->assertSee(AdminResultCap::encode($expected));
    expect($expected['tags'])->toHaveCount(20)
        ->and($expected['tags_total'])->toBe(30)
        ->and(mb_strlen($expected['description']))->toBe(500)
        ->and($expected['truncated'])->toBeTrue();

    ListingsServer::tool(McpSearchListingsTool::class, ['limit' => 11])
        ->assertHasErrors(['limit']);

    $search = app(SearchListingsTool::class)->run(['limit' => 10]);

    ListingsServer::tool(McpSearchListingsTool::class, ['limit' => 10])
        ->assertSee(AdminResultCap::encode($search));
    expect($search['rows'])->toHaveCount(10)
        ->and(mb_strlen(AdminResultCap::encode($search)))->toBeLessThanOrEqual(AdminResultCap::MAX_CHARACTERS);
});

it('AC-6 change_status returns each status and the result of a confirmed change', function (): void {
    $pending = PendingChange::factory()->create();
    $applying = PendingChange::factory()->create(['status' => 'applying']);
    $confirmed = PendingChange::factory()->create(['status' => 'confirmed', 'result' => ['status' => 'applied', 'id' => 7], 'decided_at' => now()]);
    $rejected = PendingChange::factory()->create(['status' => 'rejected', 'decided_at' => now()]);
    $failed = PendingChange::factory()->create(['status' => 'failed', 'error' => 'No listing with id 5.', 'decided_at' => now()]);
    $overdue = PendingChange::factory()->create(['expires_at' => now()->subMinute()]);

    ListingsServer::tool(ChangeStatusTool::class, ['id' => $pending->id])->assertSee('"status":"pending"');
    ListingsServer::tool(ChangeStatusTool::class, ['id' => $applying->id])->assertSee('"status":"pending"');
    ListingsServer::tool(ChangeStatusTool::class, ['id' => $confirmed->id])
        ->assertSee(['"status":"confirmed"', '"result":{"id":7,"status":"applied"}']);
    ListingsServer::tool(ChangeStatusTool::class, ['id' => $rejected->id])->assertSee('"status":"rejected"');
    ListingsServer::tool(ChangeStatusTool::class, ['id' => $failed->id])
        ->assertSee(['"status":"failed"', '"error":"No listing with id 5."']);
    ListingsServer::tool(ChangeStatusTool::class, ['id' => $overdue->id])->assertSee('"status":"expired"');

    expect($overdue->fresh()->status)->toBe('pending')
        ->and(McpToolCall::query()->where('tool', 'change_status')->where('outcome', 'ok')->count())->toBe(6)
        ->and(McpToolCall::query()->first()->result_summary)->toMatchArray(['id' => $pending->id, 'status' => 'pending']);
});

it('AC-6 change_status refuses an unknown or malformed id and logs an error row', function (): void {
    ListingsServer::tool(ChangeStatusTool::class, ['id' => (string) Str::uuid()])
        ->assertHasErrors(['No change with this id.']);
    ListingsServer::tool(ChangeStatusTool::class, ['id' => 'nope'])
        ->assertHasErrors(['No change with this id.']);

    expect(McpToolCall::query()->pluck('outcome')->all())->toBe(['error', 'error']);
});

it('AC-7 refuses mcp:start for the listings server with exit code 1 when the gate is off', function (): void {
    $run = function (): array {
        $kernel = app(Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $output = new BufferedOutput;
        $code = $kernel->handle(new ArgvInput(['artisan', 'mcp:start', 'listings']), $output);

        return [$code, $output->fetch()];
    };

    config(['ai.mcp.enabled' => true]);
    [$code, $text] = $run();
    expect($code)->toBe(1)->and($text)->toContain('runs only locally');

    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.mcp.enabled' => false]);
    [$code, $text] = $run();
    expect($code)->toBe(1)->and($text)->toContain('AI_MCP_SERVER');
});

it('AC-7 lets mcp:start through only when the app is local with the server on', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.mcp.enabled' => true]);

    $event = new CommandStarting('mcp:start', new ArgvInput(['artisan', 'mcp:start', 'listings']), new BufferedOutput);

    expect(fn () => event($event))->not->toThrow(RuntimeException::class);
});

it('AC-1 registers the listings server as a local MCP handle', function (): void {
    expect(Mcp::getLocalServer('listings'))->not->toBeNull()
        ->and(Mcp::getLocalServer('missing'))->toBeNull();
});

it('MNC-4 makes no network call while the tools run', function (): void {
    $listing = taggedListing(['family-car']);

    ListingsServer::tool(McpGetListingTool::class, ['id' => $listing->id])->assertHasNoErrors();

    Http::assertNothingSent();
});

it('AC-3 a title proposal stores a pending change, leaves the listing unchanged and names the confirm URL', function (): void {
    $listing = Listing::factory()->create(['title' => 'Old title here']);

    $response = ListingsServer::tool(ProposeTitleChangeTool::class, ['id' => $listing->id, 'title' => 'A better title']);
    $change = PendingChange::query()->sole();

    $response->assertOk()->assertHasNoErrors()->assertSee([
        $change->id,
        url('/ai/changes/'.$change->id),
        '"old_title":"Old title here"',
        '"new_title":"A better title"',
        'needs the developer in the browser',
    ]);

    expect($change->preview)->toMatchArray([
        'listing_id' => $listing->id,
        'make' => $listing->make,
        'model' => $listing->model,
        'year' => $listing->year,
        'old_title' => 'Old title here',
        'new_title' => 'A better title',
    ])
        ->and($change->status)->toBe('pending')
        ->and($change->source)->toBe('mcp')
        ->and($change->tool)->toBe('updateTitle')
        ->and($change->arguments)->toBe(['id' => $listing->id, 'title' => 'A better title'])
        ->and($change->expires_at->isFuture())->toBeTrue()
        ->and($listing->fresh()->title)->toBe('Old title here');

    $call = McpToolCall::query()->sole();

    expect($call->tool)->toBe('propose_title_change')
        ->and($call->outcome)->toBe('proposed')
        ->and($call->pending_change_id)->toBe($change->id);
});

it('AC-3 a tag proposal stores a pending change and adds no tag', function (): void {
    $listing = taggedListing(['diesel']);

    $response = ListingsServer::tool(ProposeTagTool::class, ['id' => $listing->id, 'tag' => 'family-car']);
    $change = PendingChange::query()->sole();

    $response->assertHasNoErrors()->assertSee([$change->id, url('/ai/changes/'.$change->id), '"tag":"family-car"']);

    expect($change->tool)->toBe('addTag')
        ->and($change->status)->toBe('pending')
        ->and(ListingTag::query()->where('listing_id', $listing->id)->pluck('tag')->all())->toBe(['diesel']);
});

it('AC-3 a tag proposal flags a tag the listing already has', function (): void {
    $listing = taggedListing(['diesel']);

    ListingsServer::tool(ProposeTagTool::class, ['id' => $listing->id, 'tag' => 'diesel'])->assertHasNoErrors();
    ListingsServer::tool(ProposeTagTool::class, ['id' => $listing->id, 'tag' => 'family-car'])->assertHasNoErrors();

    $previews = PendingChange::query()->orderBy('created_at')->get()->pluck('preview', 'arguments.tag');

    expect($previews['diesel']['already_tagged'])->toBeTrue()
        ->and($previews['family-car']['already_tagged'])->toBeFalse();
});

it('AC-3 caps the tags in a proposal preview and keeps the change id and url in the reply', function (): void {
    $listing = taggedListing(array_map(fn (int $number): string => 'a-fairly-long-tag-name-'.$number, range(1, 150)));

    $response = ListingsServer::tool(ProposeTagTool::class, ['id' => $listing->id, 'tag' => 'family-car'])->assertHasNoErrors();
    $change = PendingChange::query()->sole();

    expect($change->preview['tags'])->toHaveCount(20)
        ->and($change->preview['tags_total'])->toBe(150);

    $response->assertSee([$change->id, route('ai.changes.show', $change->id), '"tags_total":150', '"already_tagged":false']);
});

it('AC-3 builds the proposal url from the named route', function (): void {
    $listing = Listing::factory()->create();

    ListingsServer::tool(ProposeTagTool::class, ['id' => $listing->id, 'tag' => 'family-car'])
        ->assertSee(route('ai.changes.show', PendingChange::query()->sole()->id));
});

it('AC-3 refuses an invalid proposal without storing anything', function (): void {
    $listing = Listing::factory()->create();

    ListingsServer::tool(ProposeTitleChangeTool::class, ['id' => $listing->id, 'title' => 'x'])
        ->assertHasErrors(['The title argument must be']);
    ListingsServer::tool(ProposeTagTool::class, ['id' => 999999, 'tag' => 'family-car'])
        ->assertHasErrors(['No listing with id 999999.']);
    ListingsServer::tool(ProposeTagTool::class, ['id' => $listing->id, 'tag' => 'Not A Tag!'])
        ->assertHasErrors(['The tag argument must be']);

    expect(PendingChange::query()->count())->toBe(0)
        ->and(McpToolCall::query()->pluck('outcome')->all())->toBe(['error', 'error', 'error']);
});

it('AC-4 the server has propose tools but no confirm or apply tool', function (): void {
    $names = array_map(fn (string $class): string => app($class)->name(), (new ReflectionClass(ListingsServer::class))->getProperty('tools')->getDefaultValue());

    expect($names)->toContain('propose_title_change', 'propose_tag')
        ->and(collect($names)->filter(fn (string $name): bool => preg_match('/confirm|apply|approve|write|update|add_tag/', $name) === 1)->all())->toBe([]);

    foreach ([ProposeTitleChangeTool::class, ProposeTagTool::class] as $class) {
        expect(app($class)->toArray()['annotations']['readOnlyHint'] ?? false)->toBeFalse();
    }
});

it('AC-2 logs a failed row and answers an MCP error when a tool throws', function (): void {
    $this->mock(AdminListingRepositoryInterface::class, function ($mock): void {
        $mock->shouldReceive('search', 'find')->andThrow(new RuntimeException('database down'));
    });
    $this->mock(PendingChangeRepositoryInterface::class, function ($mock): void {
        $mock->shouldReceive('create', 'find')->andThrow(new RuntimeException('database down'));
    });
    $listing = Listing::factory()->create();

    ListingsServer::tool(McpSearchListingsTool::class, [])->assertHasErrors(['The tool failed.']);
    ListingsServer::tool(ProposeTitleChangeTool::class, ['id' => $listing->id, 'title' => 'A better title'])->assertHasErrors(['The tool failed.']);
    ListingsServer::tool(ChangeStatusTool::class, ['id' => (string) Str::uuid()])->assertHasErrors(['The tool failed.']);

    expect(McpToolCall::query()->orderBy('id')->get()->map(fn ($call): array => [$call->tool, $call->outcome, $call->error])->all())->toBe([
        ['search_listings', 'failed', RuntimeException::class],
        ['propose_title_change', 'failed', RuntimeException::class],
        ['change_status', 'failed', RuntimeException::class],
    ]);
});

it('AC-1 and AC-7 the manual test commands use ddev exec without the unsupported -T flag', function (): void {
    $manual = (string) file_get_contents(base_path('.ai/specs/spec-20261113-2230-mcp-listing-tools/manual-test.md'));

    expect($manual)->not->toContain('ddev exec -T')
        ->and($manual)->toContain('claude mcp add ai-lab -- ddev exec php artisan mcp:start listings')
        ->and($manual)->toContain('ddev exec env AI_MCP_SERVER=false php artisan mcp:start listings');
});
