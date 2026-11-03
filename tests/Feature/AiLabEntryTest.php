<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Dealer;
use App\Models\Listing;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $directory = sys_get_temp_dir().'/ai-lab-entry-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($directory.'/build');
    File::put($directory.'/build/manifest.json', json_encode([
        'resources/css/app.css' => ['file' => 'assets/app-old.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
        'resources/js/app.js' => ['file' => 'assets/app-old.js', 'src' => 'resources/js/app.js', 'isEntry' => true],
        'resources/css/ai.css' => ['file' => 'assets/ai-lab.css', 'src' => 'resources/css/ai.css', 'isEntry' => true],
        'resources/js/ai/app.ts' => ['file' => 'assets/ai-lab.js', 'src' => 'resources/js/ai/app.ts', 'isEntry' => true],
        'resources/js/ai/Pages/Lab/Overview.vue' => ['file' => 'assets/ai-overview.js', 'src' => 'resources/js/ai/Pages/Lab/Overview.vue', 'isDynamicEntry' => true],
    ], JSON_THROW_ON_ERROR));
    app()->usePublicPath($directory);
    $this->publicDirectory = $directory;
});

afterEach(function (): void {
    File::deleteDirectory($this->publicDirectory);
});

it('MNC-1 the lab uses its own entry and the old pages do not load it', function (): void {
    $this->get('/ai')
        ->assertOk()
        ->assertViewIs('ai.app')
        ->assertInertia(fn (Assert $page) => $page->component('Lab/Overview'))
        ->assertSee('/build/assets/ai-lab.css', false)
        ->assertSee('/build/assets/ai-lab.js', false)
        ->assertSee('/build/assets/ai-overview.js', false)
        ->assertDontSee('/build/assets/app-old', false);

    $listing = Listing::factory()->for(Dealer::factory())->create();

    $this->get('/listings/'.$listing->id)
        ->assertOk()
        ->assertSee('/build/assets/app-old.css', false)
        ->assertDontSee('ai-lab', false);
});

it('MNC-1 the Inertia middleware is not in the global web group', function (): void {
    expect(app(Kernel::class)->getMiddlewareGroups()['web'])->not->toContain(HandleInertiaRequests::class)
        ->and(app(Kernel::class)->getGlobalMiddleware())->not->toContain(HandleInertiaRequests::class);

    $this->get('/ai')->assertOk();

    expect(app('router')->getRoutes()->getByName('ai.overview')->gatherMiddleware())
        ->toContain(HandleInertiaRequests::class);
});
