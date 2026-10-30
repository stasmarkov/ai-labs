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
