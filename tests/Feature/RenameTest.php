<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function trackedFiles(): array
{
    $process = new Process(['git', 'ls-files', '--cached', '--others', '--exclude-standard'], base_path());
    $process->mustRun();

    return array_values(array_filter(
        explode("\n", trim($process->getOutput())),
        fn (string $path) => $path !== '' && is_file(base_path($path)),
    ));
}

function filesContaining(string $needle, array $paths): array
{
    return array_values(array_filter(
        $paths,
        fn (string $path) => stripos((string) file_get_contents(base_path($path)), $needle) !== false,
    ));
}

function underAny(string $path, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

it('AC-2 keeps interview.ddev.site only in history paths', function (): void {
    $allowed = [
        'bench/results/',
        '.ai/reports/',
        '.ai/session/',
        '.ai/specs/spec-20261110-1014-rename-ai-lab/',
        'tests/Feature/RenameTest.php',
        'tests/Feature/BenchRenameProjectTest.php',
        '.ddev/commands/host/setup',
        'tests/Feature/ServerDefaultCommandTest.php',
    ];

    $paths = array_filter(trackedFiles(), fn (string $path) => ! underAny($path, $allowed));

    expect(filesContaining('interview.ddev.site', $paths))->toBe([]);
});

it('AC-2 keeps AutoPulse only in the Q2 allowlist', function (): void {
    $allowed = [
        'product-brief.md',
        'backlog.md',
        'BENCHMARKS.md',
        'docs/learning-guide.md',
        '.ai/specs/',
        '.ai/reports/',
        '.ai/session/',
        'bench/results/',
        'tests/Feature/RenameTest.php',
    ];

    $paths = array_filter(trackedFiles(), fn (string $path) => ! underAny($path, $allowed));

    expect(filesContaining('autopulse', $paths))->toBe([]);

    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    expect(preg_match_all('/autopulse/i', $guide))->toBeLessThanOrEqual(1);
});

it('MNC-2 points app defaults at the ai-lab host', function (): void {
    $env = (string) file_get_contents(base_path('.env.example'));

    expect($env)->toContain('APP_NAME=ai-lab')
        ->and($env)->toContain('APP_URL=https://ai-lab.ddev.site')
        ->and((string) file_get_contents(base_path('.ddev/commands/host/server')))->toContain('${DDEV_HOSTNAME:-ai-lab.ddev.site}');
});

it('AC-5 keeps the old project name out of bench container names', function (): void {
    $allowed = [
        'bench/results/',
        '.ai/reports/',
        '.ai/session/',
        '.ai/specs/',
    ];
    $paths = array_filter(trackedFiles(), fn (string $path) => ! underAny($path, $allowed));

    expect(filesContaining('DDEV_SITENAME:-'.'interview', $paths))->toBe([])
        ->and(filesContaining('ddev-'.'interview-', $paths))->toBe([])
        ->and(filesContaining("DDEV_SITENAME ?? '".'interview'."'", $paths))->toBe([]);
});
