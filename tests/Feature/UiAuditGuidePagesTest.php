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

it('AC-4 F20 the ui audit covers the course page and collects every lesson from its links instead of a copied list', function (): void {
    $script = (string) file_get_contents(base_path('bench/ui-audit.mjs'));
    preg_match("/LAYOUT_ONLY_PAGES = \[(.*?)\];/s", $script, $match);
    preg_match_all("/'([^']*)'/", $match[1] ?? '', $listed);

    expect($listed[1])->toContain('learn')
        ->and($script)->toContain('async function lessonPages(')
        ->and($script)->toContain('/ai/learn`');

    foreach ((require base_path('resources/lessons/course.php'))['modules'] as $module) {
        foreach ($module['lessons'] as $lesson) {
            if ($module['slug'].'/'.$lesson['slug'] !== 'running-models/one-model-many-users') {
                expect($script)->not->toContain('learn/'.$module['slug'].'/'.$lesson['slug']);
            }
        }
    }
});
