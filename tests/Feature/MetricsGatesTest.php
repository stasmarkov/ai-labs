<?php

declare(strict_types=1);

function metricsGatesRoot(string $relative = ''): string
{
    return dirname(__DIR__, 2).($relative === '' ? '' : '/'.$relative);
}

test('MNC-1 no other ddev compose file mentions the metrics services', function () {
    foreach (glob(metricsGatesRoot('.ddev/docker-compose*.yaml')) as $file) {
        if (basename($file) === 'docker-compose.metrics.yaml') {
            continue;
        }

        expect(file_get_contents($file))->not->toMatch('/prometheus|grafana/i');
    }
});

test('MNC-3 the snapshot script only reads existing bench result files and writes under its own directory', function () {
    $script = file_get_contents(metricsGatesRoot('bench/metrics-snapshot.sh'));

    expect($script)->toContain('bench/results/metrics');

    foreach (['slow-load.sh', 'slow-load-matrix.sh', 'parity.sh', 'asset-fingerprint.sh'] as $existing) {
        expect(file_get_contents(metricsGatesRoot('bench/'.$existing)))->not->toMatch('/metrics-snapshot|metrics-matrix|9180/');
    }

    expect($script)->not->toMatch('/>\s*"?\$?\{?[A-Za-z_]*\}?\/?bench\/results\/(?!metrics)/');
});

test('MNC-5 the default test suite needs no prometheus or grafana', function () {
    foreach (['MetricsStackConfigTest.php', 'BenchMetricsSnapshotTest.php'] as $file) {
        $contents = file_get_contents(metricsGatesRoot('tests/Feature/'.$file));

        expect($contents)->not->toMatch('/Http::(get|post)|Process::run\(\s*[\'"]ddev|curl_init|fsockopen|localhost:9090/');
    }

    expect(file_get_contents(metricsGatesRoot('phpunit.xml')))->not->toMatch('/prometheus|grafana|9180/i');
});
