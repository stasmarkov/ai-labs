<?php

declare(strict_types=1);

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
            ->where('guideKey', $key)
            ->where('teach.key', $key)
            ->has('teach.works', fn (Assert $rows) => $rows->each(fn (Assert $row) => $row->has('title')->has('summary')))
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
        expect($component)->toContain("'{$part}'");
    }

    expect($component)->toContain('#${part}')->toContain('#${tabAnchors');
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
