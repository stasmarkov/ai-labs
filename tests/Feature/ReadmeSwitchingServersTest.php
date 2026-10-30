<?php

declare(strict_types=1);

function switchingServersSection(): string
{
    $readme = file_get_contents(base_path('README.md'));
    preg_match('/^## Switching servers\n(.*?)(?=^## |\z)/ms', $readme, $matches);

    return $matches[1] ?? '';
}

test('AC-13 README switching section documents the ddev server command', function (): void {
    $section = switchingServersSection();

    expect($section)->not->toBe('')
        ->toContain('ddev server')
        ->toContain('nginx-fpm')
        ->toContain('apache-fpm');
});

test('AC-13 README switching section documents the parity workflow', function (): void {
    $section = switchingServersSection();

    expect($section)->toContain('migrate:fresh --seed')
        ->toContain('bench/parity.sh capture')
        ->not->toContain('capture <server>')
        ->toContain('bench/parity.sh compare nginx-fpm apache-fpm');
    expect(strpos($section, 'migrate:fresh --seed'))->toBeLessThan(strpos($section, 'bench/parity.sh capture'));
});

test('AC-13 README switching section documents enabling debug runtime', function (): void {
    $section = switchingServersSection();

    expect($section)->toContain('DEBUG_ROUTES=true')
        ->toContain('/debug/runtime')
        ->toContain('optimize:clear')
        ->toContain('never change `.env`')
        ->toContain('.env.example');
});

test('AC-13 README switching section notes Apache reads public/.htaccess on every request', function (): void {
    expect(switchingServersSection())->toContain('public/.htaccess')->toContain('every request');
});

test('AC-11 README documents frankenphp, ZTS and logs', function (): void {
    $section = switchingServersSection();

    expect($section)->toContain('ddev server frankenphp')
        ->toContain('builds the web image')
        ->toContain('several minutes')
        ->toContain('ZTS')
        ->toContain('NTS')
        ->toContain('threads')
        ->toContain('ddev logs -f');
});

test('AC-11 README documents openlitespeed URL and htaccess restart', function (): void {
    $section = switchingServersSection();

    expect($section)->toContain('ddev server openlitespeed')
        ->toContain('https://interview.ddev.site:8091')
        ->toContain('builds the image')
        ->toContain('.htaccess')
        ->toContain('start-up')
        ->toContain('ddev restart');
});

test('AC-9 README explains how worker mode works', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    preg_match('/^## How worker mode works\n(.*?)(?=^## |\z)/ms', $readme, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('')
        ->toContain('ddev server frankenphp-octane')
        ->toContain('ddev server frankenphp-worker')
        ->toContain('frankenphp-worker.php')
        ->toContain('boots')
        ->toContain('| Octane daemon |')
        ->toContain('octane:reload')
        ->toContain('Swoole')
        ->toContain('Octane::tick')
        ->toContain('Octane::concurrently')
        ->toContain('Octane::table')
        ->toContain('ddev logs')
        ->toContain('MAX_REQUESTS')
        ->toContain('--max-requests')
        ->toContain('max_requests')
        ->toContain('vendor/laravel/octane')
        ->toContain('fresh clone of the container')
        ->toContain('needs a restart');
});

test('AC-10 README tells to restart workers after changing .env', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    preg_match('/^## How worker mode works\n(.*?)(?=^## |\z)/ms', $readme, $matches);

    expect($matches[1] ?? '')->toContain('ddev restart')->toContain('.env')->toContain('DEBUG_ROUTES');
});

test('AC-9 README names the right worker restart for each way', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    preg_match('/^## How worker mode works\n(.*?)(?=^## |\z)/ms', $readme, $matches);
    $section = $matches[1] ?? '';

    expect($section)->toContain('octane:reload --server=frankenphp')
        ->toContain('frankenphp/workers/restart')
        ->toContain('octane-server-state.json')
        ->toContain('OperationTerminated')
        ->toContain('FrankenPHP\'s own worker `max_requests` option is not used')
        ->toContain('OCTANE_HTTPS')
        ->not->toContain('octane:reload` is a quicker alternative on both servers');
});

test('AC-10 manual test documents the octane:reload and logs steps', function (): void {
    $manual = file_get_contents(base_path('.ai/specs/spec-20261030-1437-octane-worker-mode/manual-test.md'));

    expect($manual)->toContain('## Step 7')
        ->toContain('ddev artisan octane:reload')
        ->toContain('Reloading workers')
        ->toContain('ddev logs');
});
