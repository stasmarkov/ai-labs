<?php

declare(strict_types=1);

use App\Guides\GuideRenderer;
use App\Guides\GuideRepository;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    Http::fake(['*/api/version' => Http::response(['version' => '0.12.0'])]);
});

function adminAssistantLocal(bool $enabled = true): void
{
    app()->detectEnvironment(fn (): string => 'local');
    config(['ai.admin_assistant.enabled' => $enabled]);
}

it('AC-12 the admin assistant page gets the three endpoints, the limits, the example questions and its guide', function (): void {
    adminAssistantLocal();

    $this->get('/ai/admin-assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/AdminAssistant')
            ->where('askUrl', '/api/admin-assistant/ask')
            ->where('confirmUrl', '/api/admin-assistant/actions/ACTIONID/confirm')
            ->where('rejectUrl', '/api/admin-assistant/actions/ACTIONID/reject')
            ->where('actionPlaceholder', 'ACTIONID')
            ->where('runUrl', '/ai/runs/RUN_ID')
            ->where('idPlaceholder', 'RUN_ID')
            ->where('guideHref', '/ai/guides/admin-assistant')
            ->where('maxQuestionLength', 500)
            ->where('maxSteps', 4)
            ->has('model')
            ->has('howItWorks', 6)
            ->has('examples', 4));
});

it('AC-12 the example questions are plain non-empty questions within the question limit', function (): void {
    adminAssistantLocal();

    $examples = $this->get('/ai/admin-assistant')->viewData('page')['props']['examples'];

    foreach ($examples as $example) {
        expect($example)->toBeString()->not->toBeEmpty()
            ->and(mb_strlen($example))->toBeLessThanOrEqual(500);
    }
});

it('AC-12 the page is in the navigation and on the overview only while the assistant is available, and answers 404 otherwise', function (): void {
    adminAssistantLocal();

    $navigation = $this->get('/ai/admin-assistant')->viewData('page')['props']['lab']['navigation'];
    $overview = $this->get('/ai')->viewData('page')['props'];
    $keys = array_column($overview['capabilities'], 'key');
    $capability = $overview['capabilities'][array_search('admin-assistant', $keys, true)];

    expect(array_column($navigation, 'href'))->toContain('/ai/admin-assistant')
        ->and($capability['route'])->toBe('/ai/admin-assistant')
        ->and($capability['status'])->toBe('ready')
        ->and($capability['guideKey'])->toBe('admin-assistant');

    adminAssistantLocal(false);

    $this->get('/ai/admin-assistant')->assertNotFound();
    expect(array_column($this->get('/ai')->viewData('page')['props']['capabilities'], 'key'))->not->toContain('admin-assistant')
        ->and(array_column($this->get('/ai')->viewData('page')['props']['lab']['navigation'], 'href'))->not->toContain('/ai/admin-assistant');

    app()->detectEnvironment(fn (): string => 'production');
    config(['ai.admin_assistant.enabled' => true]);

    $this->get('/ai/admin-assistant')->assertNotFound();
});

it('AC-12 the guide answers 200 with six tabs and no slice ids or paths', function (): void {
    $guide = app(GuideRepository::class)->find('admin-assistant');
    $rendered = app(GuideRenderer::class)->render('admin-assistant');

    expect($guide->kind)->toBe('capability')
        ->and($rendered['works'])->not->toBeEmpty()
        ->and($rendered['failures'])->not->toBeEmpty()
        ->and($rendered['recipes'])->not->toBeEmpty()
        ->and($rendered['support'])->not->toBeEmpty()
        ->and($rendered['numbers'])->not->toBeEmpty()
        ->and($rendered['experiments'])->not->toBeEmpty();

    $this->get('/ai/guides/admin-assistant')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Guide')
            ->has('guide.tabs', 6));
});

it('AC-12 the learning guide has the admin assistant section and the readme describes it', function (): void {
    $learning = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $readme = (string) file_get_contents(base_path('README.md'));

    expect($learning)->toContain('Admin assistant')->toContain('/ai/admin-assistant')
        ->and($readme)->toContain('/ai/admin-assistant')->toContain('AI_ADMIN_ASSISTANT');
});

it('AC-11 the admin assistant page answers 404 outside the local environment with the switch on', function (string $environment): void {
    app()->detectEnvironment(fn (): string => $environment);
    config(['ai.admin_assistant.enabled' => true]);

    $this->get('/ai/admin-assistant')->assertNotFound();
})->with(['production', 'staging', 'testing']);
