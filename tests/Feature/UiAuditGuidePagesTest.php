<?php

declare(strict_types=1);

function uiAuditFixedPages(): array
{
    $script = (string) file_get_contents(base_path('bench/ui-audit.mjs'));
    preg_match('/LAYOUT_ONLY_PAGES = \\[(.*?)\\];/s', $script, $match);
    preg_match_all("/'([^']*)'/", $match[1] ?? '', $listed);

    return $listed[1];
}

it('AC-9 the ui audit collects every guide page from the links on the course page instead of a copied list', function (): void {
    $script = (string) file_get_contents(base_path('bench/ui-audit.mjs'));

    expect($script)->toContain('async function guidePages(')
        ->and($script)->toContain('/ai/learn`')
        ->and(uiAuditFixedPages())->toContain('learn', 'learn/running-models/one-model-many-users')
        ->and(uiAuditFixedPages())->not->toContain('guides');

    foreach (glob(base_path('resources/guides/*.php')) as $file) {
        $key = basename($file, '.php');
        expect(uiAuditFixedPages())->not->toContain('guides/'.$key);
    }
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

it('AC-9 the guide links reachable from the course page equal every guide key', function (): void {
    $reachable = [];

    $this->get('/ai/learn')->assertOk()->assertInertia(function ($page) use (&$reachable): void {
        $props = $page->toArray()['props'];

        foreach ($props['course']['modules'] as $module) {
            foreach ($module['references'] as $reference) {
                $reachable[] = $reference['href'];
            }
            foreach ($module['lessons'] as $lesson) {
                foreach ($lesson['guides'] ?? [] as $guide) {
                    $reachable[] = $guide['href'];
                }
            }
        }
    });

    $source = (string) file_get_contents(resource_path('js/ai/Pages/Lab/Learn.vue'));
    preg_match_all('#href="(/ai/guides/[a-z0-9-]+)"#', $source, $linked);

    $keys = array_map(
        fn (string $href): string => basename($href),
        array_merge($reachable, $linked[1]),
    );
    $guideKeys = array_map(fn (string $file): string => basename($file, '.php'), glob(resource_path('guides/*.php')));

    sort($keys);
    sort($guideKeys);

    expect(array_values(array_unique($keys)))->toBe($guideKeys);
});
