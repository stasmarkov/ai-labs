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
