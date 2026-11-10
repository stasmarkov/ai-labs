<?php

declare(strict_types=1);

use App\Guides\GuideEventTypes;
use App\Services\GuidePageService;
use Inertia\Testing\AssertableInertia as Assert;

it('AC-5 each live page carries the guide steps for its panel', function (string $path, string $component): void {
    $this->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('howItWorks', fn (Assert $steps) => $steps->each(fn (Assert $step) => $step
                ->has('title')
                ->has('html')
                ->has('event')
                ->missing('snippet'))));
})->with([
    'describe' => ['/ai/describe', 'Lab/Describe'],
    'semantic' => ['/ai/semantic', 'Lab/Semantic'],
    'nl-search' => ['/ai/nl-search', 'Lab/NlSearch'],
    'docs' => ['/ai/docs', 'Lab/Docs'],
    'chat' => ['/ai/chat', 'Lab/Chat'],
]);

it('AC-5 every panel step event is a known protocol event', function (string $path): void {
    $steps = $this->get($path)->viewData('page')['props']['howItWorks'];

    expect($steps)->not->toBeEmpty();

    foreach ($steps as $step) {
        expect($step['event'])->toBeIn([null, ...GuideEventTypes::KNOWN]);
    }
})->with(['/ai/describe', '/ai/semantic', '/ai/nl-search', '/ai/docs', '/ai/chat']);

it('AC-5 GuideEventTypes lists every event type the server and the lab pages use', function (): void {
    $found = [];

    foreach (glob(base_path('app/{Ai,Services}/*.php'), GLOB_BRACE) as $file) {
        preg_match_all("/'type' => '([a-z_]+)'/", (string) file_get_contents($file), $matches);
        $found = [...$found, ...$matches[1]];
    }

    foreach ([
        'resources/js/ai/composables/useEventStream.ts',
        'resources/js/ai/Pages/Lab/Chat.vue',
        'resources/js/ai/components/LabHowItWorks.vue',
    ] as $file) {
        preg_match_all("/type === '([a-z_]+)'/", (string) file_get_contents(base_path($file)), $matches);
        $found = [...$found, ...$matches[1]];
    }

    $found = array_values(array_diff(array_unique($found), ['unparsed']));

    expect($found)->not->toBeEmpty()
        ->and(array_diff($found, GuideEventTypes::KNOWN))->toBe([]);
});

it('AC-5 the panel marks the active step with an icon and aria-current', function (): void {
    $source = (string) file_get_contents(base_path('resources/js/ai/components/LabHowItWorks.vue'));

    expect($source)->toContain('aria-current')
        ->and($source)->toContain('activeStep');
});

it('MNC-2 the live pages still declare their own props next to the panel', function (): void {
    $this->get('/ai/describe')->assertInertia(fn (Assert $page) => $page
        ->has('describeUrl')->has('runUrl')->has('listings'));
});

it('AC-5 each live page links to its full guide', function (string $path, string $key): void {
    $href = $this->get($path)->assertOk()->viewData('page')['props']['guideHref'] ?? null;

    expect($href)->toBe('/ai/guides/'.$key);
    $this->get($href)->assertOk();
})->with([
    'describe' => ['/ai/describe', 'describe'],
    'semantic' => ['/ai/semantic', 'semantic'],
    'nl-search' => ['/ai/nl-search', 'nl-search'],
    'docs' => ['/ai/docs', 'docs'],
    'chat' => ['/ai/chat', 'chat'],
]);

it('AC-5 the panel renders failed, stopped and refused end states and the full guide link', function (): void {
    $source = (string) file_get_contents(base_path('resources/js/ai/components/LabHowItWorks.vue'));
    $steps = (string) file_get_contents(base_path('resources/js/ai/composables/useGuideSteps.ts'));

    expect($source)->toContain('Open the full guide')
        ->and($source)->toContain("return 'failed';")
        ->and($source)->toContain("return 'stopped';")
        ->and($source)->toContain("refused: 'refused'")
        ->and($source)->toContain('✗')
        ->and($source)->toContain('■')
        ->and($source)->toContain("aria-current=\"isCurrent(index) ? 'step' : undefined\"")
        ->and($steps)->toContain("const EARLY_END_EVENTS = ['refused', 'no_context'];");
});

it('AC-5 the NL search guard step lights up on a refusal', function (): void {
    $steps = app(GuidePageService::class)->howItWorks('nl-search');

    expect(array_column($steps, 'event'))->toContain('refused')
        ->and((string) file_get_contents(base_path('resources/js/ai/Pages/Lab/NlSearch.vue')))->toContain("return 'refused';");
});
