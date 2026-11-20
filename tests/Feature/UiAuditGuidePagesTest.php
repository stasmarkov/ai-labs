<?php

declare(strict_types=1);

it('AC-8 the ui audit covers the logs-restart guide page', function (): void {
    $script = (string) file_get_contents(base_path('bench/ui-audit.mjs'));
    preg_match("/LAYOUT_ONLY_PAGES = \[(.*?)\];/s", $script, $match);
    preg_match_all("/'([^']*)'/", $match[1] ?? '', $listed);

    expect($listed[1])->toContain('guides/logs-restart');
});

it('AC-7 the ui audit covers the worker-dx guide page', function (): void {
    $script = (string) file_get_contents(base_path('bench/ui-audit.mjs'));
    preg_match("/LAYOUT_ONLY_PAGES = \[(.*?)\];/s", $script, $match);
    preg_match_all("/'([^']*)'/", $match[1] ?? '', $listed);

    expect($listed[1])->toContain('guides/worker-dx');
});

it('AC-10 the ui audit covers the lessons guide, the course page and the pilot lesson', function (): void {
    $script = (string) file_get_contents(base_path('bench/ui-audit.mjs'));
    preg_match("/LAYOUT_ONLY_PAGES = \[(.*?)\];/s", $script, $match);
    preg_match_all("/'([^']*)'/", $match[1] ?? '', $listed);

    expect($listed[1])->toContain('guides/lessons', 'learn', 'learn/running-models/one-model-many-users');
});

it('AC-4 the ui audit covers the course page and all eight lessons', function (): void {
    $script = (string) file_get_contents(base_path('bench/ui-audit.mjs'));
    preg_match("/LAYOUT_ONLY_PAGES = \[(.*?)\];/s", $script, $match);
    preg_match_all("/'([^']*)'/", $match[1] ?? '', $listed);

    $expected = ['learn'];
    foreach ((require base_path('resources/lessons/course.php'))['modules'] as $module) {
        foreach ($module['lessons'] as $lesson) {
            $expected[] = 'learn/'.$module['slug'].'/'.$lesson['slug'];
        }
    }

    expect($expected)->toHaveCount(9)
        ->and($listed[1])->toContain(...$expected);
});
