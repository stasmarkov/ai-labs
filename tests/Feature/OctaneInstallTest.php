<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;

it('MNC-4 octane install is committed and needs no untracked file or binary', function (): void {
    expect(base_path('config/octane.php'))->toBeFile()
        ->and(base_path('public/frankenphp-worker.php'))->toBeFile()
        ->and(file_get_contents(base_path('.env.example')))->toContain("OCTANE_SERVER=frankenphp\n");

    $ignore = array_map('trim', file(base_path('.gitignore')));

    expect($ignore)->toContain('/frankenphp')
        ->and($ignore)->not->toContain('frankenphp')
        ->and($ignore)->not->toContain('public/frankenphp-worker.php')
        ->and($ignore)->not->toContain('/public/frankenphp-worker.php')
        ->and(base_path('frankenphp'))->not->toBeFile();
});

it('MNC-3 octane is a required dependency defaulting to frankenphp', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);

    expect($composer['require'])->toHaveKey('laravel/octane')
        ->and(config('octane.server'))->toBe('frankenphp');
});

it('MNC-2 the app does not trust forwarded headers; servers set HTTPS themselves', function (): void {
    Route::get('/_secure-probe', fn (): string => request()->isSecure() ? 'secure' : 'plain');

    $this->get('http://localhost/_secure-probe', ['X-Forwarded-Proto' => 'https'])->assertSee('plain');
});

it('AC-6 the worker script defines LARAVEL_START so the /up body matches the other servers', function (): void {
    expect(file_get_contents(base_path('public/frankenphp-worker.php')))->toContain("define('LARAVEL_START'");
});
