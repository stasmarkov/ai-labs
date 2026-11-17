<?php

declare(strict_types=1);

function workerDxManualStep(int $number): string
{
    $plan = file_get_contents(base_path('.ai/specs/spec-20261117-1422-worker-dx/manual-test.md'));
    preg_match('/^## Step '.$number.'\n(.*?)(?=^## Step |\z)/ms', $plan, $matches);

    return $matches[1] ?? '';
}

test('MNC-1 manual step 1 loops parity capture over the three modes with dev-watch off', function (): void {
    $step = workerDxManualStep(1);

    expect($step)->not->toBe('')
        ->toContain('for mode in frankenphp frankenphp-octane frankenphp-worker')
        ->toContain('ddev server $mode')
        ->toContain('ddev dev-watch off')
        ->toContain('PARITY_INSECURE=1 bench/parity.sh capture')
        ->toContain('bench/asset-fingerprint.sh')
        ->toContain('app-DNob7CFE.css')
        ->toContain('app-PTzu1QaN.js')
        ->toContain('captured=');
});

test('AC-1 AC-2 AC-3 manual step 2 uses the probe and restores it from git', function (): void {
    $step = workerDxManualStep(2);

    expect($step)->not->toBe('')
        ->toContain('bench/reload-latency.sh')
        ->toContain('app/Support/ReloadProbe.php')
        ->toContain('git checkout -- app/Support/ReloadProbe.php')
        ->toContain('ddev artisan frankenphp:workers:restart')
        ->not->toContain('routes/web.php');
});

test('MNC-3 manual step 4 listens again after xdebug off and a restart and expects no connection', function (): void {
    $step = workerDxManualStep(4);

    expect($step)->not->toBe('')
        ->toContain('ddev xdebug off')
        ->toContain('ddev restart')
        ->toContain('connections=0')
        ->and(substr_count($step, 'php bench/xdebug-check.php'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($step, 'XDEBUG_TRIGGER=1'))->toBeGreaterThanOrEqual(2);
});

test('AC-4 manual step 4 checks the untriggered request with its own listener run with --wait=3', function (): void {
    $step = workerDxManualStep(4);
    $untriggeredCurl = strpos($step, 'curl -sk https://ai-lab.ddev.site/api/ping');
    $shortListener = strpos($step, 'php bench/xdebug-check.php --wait=3');

    expect(substr_count($step, 'php bench/xdebug-check.php'))->toBeGreaterThanOrEqual(3)
        ->and($shortListener)->not->toBeFalse()
        ->and($untriggeredCurl)->not->toBeFalse()
        ->and($shortListener)->toBeLessThan($untriggeredCurl);
});
