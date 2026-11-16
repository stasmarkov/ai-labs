<?php

declare(strict_types=1);

use App\Guides\GuideRepository;
use App\Guides\InvalidGuideException;
use App\Models\PendingChange;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);
    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.admin_assistant.enabled' => true, 'ai.mcp.enabled' => true]);
});

/**
 * Every lab page that teaches, as [url path, Vue page, guide key].
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
function teachPages(): array
{
    return [
        'describe' => ['/ai/describe', 'Describe', 'describe'],
        'semantic' => ['/ai/semantic', 'Semantic', 'semantic'],
        'nl-search' => ['/ai/nl-search', 'NlSearch', 'nl-search'],
        'docs' => ['/ai/docs', 'Docs', 'docs'],
        'chat' => ['/ai/chat', 'Chat', 'chat'],
        'photos' => ['/ai/photos', 'Photos', 'photos'],
        'photo-search' => ['/ai/photo-search', 'PhotoSearch', 'photo-search'],
        'photo-duplicates' => ['/ai/photos/duplicates', 'PhotoDuplicates', 'photo-duplicates'],
        'assistant' => ['/ai/assistant', 'Assistant', 'assistant'],
        'moderation' => ['/ai/moderation', 'Moderation', 'moderation'],
        'research' => ['/ai/research', 'Research', 'research'],
        'playground' => ['/ai/playground', 'Playground', 'playground'],
        'admin-assistant' => ['/ai/admin-assistant', 'AdminAssistant', 'admin-assistant'],
        'changes' => ['/ai/changes', 'Changes', 'mcp'],
        'settings' => ['/ai/settings', 'Settings', 'providers'],
    ];
}

function teachSource(string $name): string
{
    return (string) file_get_contents(base_path('resources/js/ai/Pages/Lab/'.$name.'.vue'));
}

it('AC-3 every lab page passes a teach block with all four parts and its guide key', function (string $path, string $page, string $key): void {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Lab/'.$page)
            ->missing('guideKey')
            ->missing('howItWorks')
            ->where('teach.key', $key)
            ->where('teach.href', route('ai.guides.show', ['key' => $key], false))
            ->has('teach.works', fn (Assert $rows) => $rows->each(fn (Assert $row) => $row->has('title')->has('summary')->has('event')))
            ->has('teach.build')
            ->has('teach.watch')
            ->has('teach.elsewhere'));

    $teach = $this->get($path)->viewData('page')['props']['teach'];

    foreach (['works', 'build', 'watch', 'elsewhere'] as $part) {
        expect($teach[$part])->not->toBeEmpty();
    }
})->with(array_values(teachPages()));

it('AC-3 every lab page renders the LabTeach component', function (string $path, string $page): void {
    expect(teachSource($page))
        ->toMatch('/<LabTeach\b/')
        ->toContain("from '../../components/LabTeach.vue'");
})->with(array_map(fn (array $row): array => [$row[0], $row[1]], array_values(teachPages())));

it('AC-3 LabTeach shows the four parts and links each one to its guide tab', function (): void {
    $component = (string) file_get_contents(base_path('resources/js/ai/components/LabTeach.vue'));

    expect($component)
        ->toContain('data-lab-teach')
        ->toContain('data-guide-link');

    foreach (['works', 'build', 'watch', 'elsewhere'] as $part) {
        expect($component)->toMatch("/'{$part}'|\"{$part}\"/");
    }

    expect($component)->toContain('#works')->toContain('#${part}')->toContain('#${row.tab}');
});

it('NFR-1 the collapsible parts of LabTeach are details and summary elements', function (): void {
    $component = (string) file_get_contents(base_path('resources/js/ai/components/LabTeach.vue'));

    expect($component)
        ->toContain('<details')
        ->toContain('<summary')
        ->toContain('data-teach-part')
        ->not->toContain('v-html');
});

it('NFR-1 an example chip is a button of type button with the data-example marker and visible text', function (string $path, string $page): void {
    $source = teachSource($page).(string) file_get_contents(base_path('resources/js/ai/components/LabExamples.vue'));

    preg_match_all('/<(?:button|LabButton)\b[^>]*data-example[^>]*>(.*?)<\/(?:button|LabButton)>/s', $source, $chips, PREG_SET_ORDER);

    foreach ($chips as $chip) {
        expect($chip[0])->toMatch('/type="button"|<LabButton/');
        expect(trim(strip_tags($chip[1])))->not->toBe('');
    }

    expect($source)->not->toBeEmpty();
})->with(array_map(fn (array $row): array => [$row[0], $row[1]], array_values(teachPages())));

it('AC-4 pages with a main text input offer example chips', function (string $page): void {
    expect(teachSource($page))->toMatch('/data-example|<LabExamples/');
})->with([
    'Describe', 'Semantic', 'NlSearch', 'Docs', 'Chat', 'PhotoSearch', 'Assistant', 'Moderation', 'Research', 'Playground', 'AdminAssistant',
]);

it('AC-4 every text field on a lab page has a placeholder or a hint', function (string $page): void {
    $source = teachSource($page);

    preg_match_all('/<(?:input|textarea|LabTextInput)\b[^>]*>/s', $source, $fields);

    expect($source)->not->toBeEmpty();

    foreach ($fields[0] as $tag) {
        if (preg_match('/type="(?:checkbox|radio|range|file|hidden|button)"/', $tag) === 1) {
            continue;
        }

        expect($tag)->toMatch('/placeholder=|:placeholder=|aria-describedby=|:aria-describedby=|:hint=|\bhint=/');
    }
})->with(array_map(fn (array $row): array => [$row[1]], array_values(teachPages())));

it('AC-4 static example arrays hold 2 to 4 entries', function (string $page): void {
    expect(preg_match('/const EXAMPLES = \[(.*?)\];/s', teachSource($page), $match))->toBe(1);

    $body = $match[1];
    $count = str_contains($body, '{')
        ? substr_count($body, '{')
        : preg_match_all('/\'(?:[^\'\\\\]|\\\\.)*\'/', $body);

    expect($count)->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4);
})->with(['Semantic', 'NlSearch', 'Docs', 'Chat', 'Playground']);

it('AC-4 Describe offers 2 to 4 example listing ids', function (): void {
    expect(teachSource('Describe'))->toMatch('/listings\.slice\(0, [234]\)/');
});

it('AC-4 prop-driven pages pass 2 to 4 examples', function (string $path): void {
    $examples = $this->get($path)->assertOk()->viewData('page')['props']['examples'];

    expect(count($examples))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(4);
})->with(['/ai/research', '/ai/assistant', '/ai/admin-assistant', '/ai/photo-search', '/ai/moderation']);

it('AC-3 the How it works part holds every works step of the guide, 3 to 6 of them, on every lab page', function (string $path, string $page, string $key): void {
    $works = $this->get($path)->assertOk()->viewData('page')['props']['teach']['works'];
    $guide = app(GuideRepository::class)->find($key);

    expect(count($works))->toBeBetween(3, 6)
        ->and(array_column($works, 'title'))->toBe(array_map(fn ($step): string => $step->title, $guide->works));
})->with(array_values(teachPages()));

it('F2 every lab page renders the How it works part from teach.works, with no separate steps prop or slot', function (string $path, string $page): void {
    expect(teachSource($page))
        ->not->toContain('howItWorks')
        ->not->toContain('LabHowItWorks')
        ->not->toContain('#works');
})->with(array_map(fn (array $row): array => [$row[0], $row[1]], array_values(teachPages())));

it('F14 LabTeach memoises the static parts so stream updates do not re-render them, and has no slot', function (): void {
    $component = (string) file_get_contents(base_path('resources/js/ai/components/LabTeach.vue'));

    expect($component)
        ->toContain('v-memo="[teach]"')
        ->toContain('<LabHowItWorks')
        ->not->toContain('<slot');
});

it('F10 F19 LabTeach builds guide links from the server href and opens public docs without a referrer', function (): void {
    $component = (string) file_get_contents(base_path('resources/js/ai/components/LabTeach.vue'));

    expect($component)
        ->toContain('teach.href')
        ->not->toContain('/ai/guides/')
        ->not->toContain('TAB_KEYS')
        ->toMatch('/<a\b[^>]*rel="noopener noreferrer"[^>]*target="_blank"|<a\b[^>]*target="_blank"[^>]*rel="noopener noreferrer"/');
});

it('F3 a Describe example chip picks the listing in the select and clears the free id, so the chosen id is sent', function (): void {
    $source = teachSource('Describe');

    expect($source)->toMatch('/<LabExamples[^>]*@pick="selectedId = \$event; freeId = \'\'"/')
        ->toMatch('/const listingId = computed\(\(\) => \(freeId\.value\.trim\(\) !== \'\' \? freeId\.value\.trim\(\) : selectedId\.value\)\)/');
});

it('F21 Playground offers its example inputs through LabExamples', function (): void {
    $source = teachSource('Playground');

    expect($source)->toMatch('/<LabExamples\b[^>]*:examples="EXAMPLES"[^>]*@pick="input = \$event"/')
        ->not->toMatch('/<button\b[^>]*data-example/');
});

it('F4 the change detail page carries the same teach block as the changes list', function (): void {
    $change = PendingChange::factory()->create();

    $this->get('/ai/changes/'.$change->id)
        ->assertOk()
        ->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Lab/Changes')
            ->where('teach.key', 'mcp')
            ->has('teach.works'));
});

it('F12 a partial reload of the changes page never builds the teach block', function (): void {
    $version = (string) ($this->get('/ai/changes')->viewData('page')['version'] ?? '');
    $this->app->scoped(GuideRepository::class, fn (): GuideRepository => new GuideRepository(sys_get_temp_dir().'/no-guides-'.bin2hex(random_bytes(4))));
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version];

    $this->withoutExceptionHandling();

    expect(fn () => $this->get('/ai/changes', $headers))->toThrow(InvalidGuideException::class, 'mcp');

    $this->get('/ai/changes', [
        ...$headers,
        'X-Inertia-Partial-Component' => 'Lab/Changes',
        'X-Inertia-Partial-Data' => 'pending,recent',
    ])->assertOk()->assertJsonMissingPath('props.teach')->assertJsonPath('component', 'Lab/Changes');
});
