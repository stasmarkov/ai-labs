<?php

declare(strict_types=1);

const NGINX_FPM_DEFAULT_PATTERN = '/(default|by default)[^.\n]{0,40}nginx-fpm|nginx-fpm[^.\n]{0,20}(is|by) (the )?default/i';

test('AC-4 README and CLAUDE.md name frankenphp as the default and never call nginx-fpm the default', function (): void {
    foreach (['README.md', 'CLAUDE.md'] as $file) {
        $doc = file_get_contents(base_path($file));

        expect($doc)->toContain('ddev setup')
            ->toContain('frankenphp');
        expect(preg_match(NGINX_FPM_DEFAULT_PATTERN, $doc))->toBe(0);
        expect(preg_match('/nginx-fpm, PHP 8\.4/', $doc))->toBe(0);
    }

    expect(file_get_contents(base_path('README.md')))->toContain('The default server is frankenphp');
});

test('AC-4 the guide and BENCHMARKS.md carry a default-server note', function (): void {
    expect(file_get_contents(base_path('docs/learning-guide.md')))->toContain('The default server is now `frankenphp`');
    expect(file_get_contents(base_path('BENCHMARKS.md')))->toContain('The default server is now `frankenphp`');
});

test('AC-4 manual-test plans do not name nginx-fpm as the resting server', function (): void {
    foreach (glob(base_path('.ai/specs/*/manual-test.md')) as $file) {
        expect(file_get_contents($file))->not->toMatch('/^- (Project running on|You are on|Start on) `?nginx-fpm/mi', basename(dirname($file)));
    }
});

test('AC-4 the guide puts the default-server note in section 2 and never says nginx + PHP-FPM by default', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    expect(preg_match('/^## 2\..*?^## 3\./ms', $guide, $section))->toBe(1);
    expect($section[0])->toContain('The default server is now `frankenphp`');
    expect(substr_count($guide, 'The default server is now `frankenphp`'))->toBe(1);
    expect($guide)->not->toContain('nginx + PHP-FPM by default');
});

test('AC-4 manual-test plans never end on or go back to nginx-fpm as the default server', function (): void {
    $resting = '/end on `?nginx-fpm|ends on `?nginx-fpm|back to (the default server|nginx-fpm)|default server, nginx-fpm|switch back to nginx-fpm|ddev server nginx-fpm`?\s+#[^\n]*(back|default)|finish with `ddev server nginx-fpm`|run `ddev server nginx-fpm` when you finish/i';

    foreach (glob(base_path('.ai/specs/*/manual-test.md')) as $file) {
        expect(file_get_contents($file))->not->toMatch($resting, basename(dirname($file)));
    }
});

test('AC-4 the doc pattern matches every sentence that calls nginx-fpm the default', function (string $sentence): void {
    expect(preg_match(NGINX_FPM_DEFAULT_PATTERN, $sentence))->toBe(1);
})->with([
    'is the default' => 'nginx-fpm is the default server',
    'by default' => 'The project runs on nginx-fpm by default.',
    'default server is' => 'the default server is nginx-fpm',
    'by default first' => 'By default the stack uses nginx-fpm',
]);

test('AC-4 README parity examples compare against frankenphp and end on the default server, and CLAUDE.md explains config.yaml', function (): void {
    $readme = file_get_contents(base_path('README.md'));

    expect(substr_count($readme, 'compare frankenphp '))->toBe(2);
    expect($readme)->not->toContain('compare nginx-fpm');
    expect(preg_match('/```sh\n(?:(?!```).)*bench\/parity\.sh compare frankenphp frankenphp-worker\nddev server default[^\n]*\n```/s', $readme))->toBe(1);
    expect(file_get_contents(base_path('CLAUDE.md')))->toContain('still says `webserver_type: nginx-fpm` on purpose');
});

test('AC-4 no manual-test plan closes a fenced block with ddev server nginx-fpm or restores it in a Rollback section', function (): void {
    foreach (glob(base_path('.ai/specs/*/manual-test.md')) as $file) {
        $plan = basename(dirname($file));
        $text = file_get_contents($file);

        preg_match_all('/```[a-z]*\n(.*?)```/s', $text, $blocks);
        foreach ($blocks[1] as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn (string $line): bool => $line !== ''));
            expect(end($lines) ?: '')->not->toMatch('/^ddev server nginx-fpm\s*(#.*)?$/', $plan.': a fenced block ends on nginx-fpm');
        }

        foreach (preg_split('/^(?=## )/m', $text) as $section) {
            if (preg_match('/^## [^\n]*(rollback|cleanup)/i', $section) === 1) {
                expect($section)->not->toMatch('/ddev server nginx-fpm(?![\w-])/', $plan.': a Rollback section restores nginx-fpm');
            }
        }

        expect($text)->not->toMatch('/^Rollback[^\n]*\n+```[a-z]*\n(?:(?!```).)*?ddev server nginx-fpm\s*(#.*)?\n(?:(?!```).)*```/ms', $plan.': an inline Rollback block restores nginx-fpm');
        expect($text)->not->toMatch('/Leave the server on nginx-fpm|rollback[^\n]{0,80}`ddev server nginx-fpm`/i', $plan);
    }
});

test('AC-4 the bare-rollback check catches the lines it is meant to catch', function (): void {
    $closing = "```sh\nddev artisan config:clear\nddev server nginx-fpm\n```\n";
    preg_match_all('/```[a-z]*\n(.*?)```/s', $closing, $blocks);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $blocks[1][0]))));

    expect(end($lines))->toMatch('/^ddev server nginx-fpm\s*(#.*)?$/');
    expect('ddev server nginx-fpm; echo "exit=$?"')->not->toMatch('/^ddev server nginx-fpm\s*(#.*)?$/');
    expect("Rollback:\n\n```sh\nddev server nginx-fpm\nrm x\n```\n")->toMatch('/^Rollback[^\n]*\n+```[a-z]*\n(?:(?!```).)*?ddev server nginx-fpm\s*(#.*)?\n(?:(?!```).)*```/ms');
    expect("## Rollback\n\n```sh\nddev server nginx-fpm\nrm x\n```\n")->toMatch('/^## [^\n]*rollback.*ddev server nginx-fpm(?![\w-])/is');
});

test('AC-4 BENCHMARKS.md top note and the guide section 2 note both mention ddev setup', function (): void {
    $benchmarks = file_get_contents(base_path('BENCHMARKS.md'));
    $top = substr($benchmarks, 0, strpos($benchmarks, "\n## "));
    $guide = file_get_contents(base_path('docs/learning-guide.md'));
    preg_match('/^## 2\..*?^## 3\./ms', $guide, $section);

    expect($top)->toContain('The default server is now `frankenphp`')->toContain('ddev setup');
    expect($section[0])->toContain('ddev setup');
});
