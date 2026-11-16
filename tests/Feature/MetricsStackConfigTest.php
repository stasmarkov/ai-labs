<?php

declare(strict_types=1);
use Symfony\Component\Yaml\Yaml;

const METRICS_CADDYFILES = [
    'frankenphp' => '.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp',
    'frankenphp-worker' => '.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp',
    'frankenphp-octane' => '.ddev/servers/frankenphp-octane/Caddyfile',
];

function metricsConfigFile(string $relative): string
{
    $path = dirname(__DIR__, 2).'/'.$relative;

    if (! is_file($path)) {
        test()->fail("{$relative} does not exist.");
    }

    return file_get_contents($path);
}

test('AC-1 every mode serves metrics on port 9180 and records request metrics without a per-host label', function (string $mode, string $relative) {
    $conf = metricsConfigFile($relative);

    expect($conf)->toMatch('/^:9180\s*\{\s*metrics\s*\}/m')
        ->and($conf)->toMatch('/servers\s*\{[^}]*^\s*metrics\s*$/ms')
        ->and($conf)->not->toContain('per_host');
})->with(fn () => collect(METRICS_CADDYFILES)->map(fn (string $relative, string $mode) => [$mode, $relative])->all());

test('NFR-1 the admin API is never bound beyond localhost and 9180 is not published', function (string $mode, string $relative) {
    $conf = metricsConfigFile($relative);

    expect($conf)->not->toMatch('/^\s*admin\s+(?!localhost|\{\$CADDY_SERVER_ADMIN_HOST\})/mi')
        ->and($conf)->not->toMatch('/0\.0\.0\.0:2019/');
})->with(fn () => collect(METRICS_CADDYFILES)->map(fn (string $relative, string $mode) => [$mode, $relative])->all());

test('NFR-1 octane starts from the committed Caddyfile with the admin host on localhost', function () {
    $conf = metricsConfigFile('.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml');

    expect($conf)->toContain('--caddyfile=/var/www/html/.ddev/servers/frankenphp-octane/Caddyfile')
        ->and($conf)->not->toContain('--admin-host')
        ->and($conf)->not->toMatch('/container_port:\s*(9180|2019)/');
});

test('NFR-1 no mode publishes 9180 or 2019 through the router or the host', function () {
    foreach (['.ddev/servers/frankenphp/docker-compose.frankenphp.yaml', '.ddev/docker-compose.frankenphp.yaml'] as $relative) {
        expect(metricsConfigFile($relative))->not->toMatch('/9180|2019/');
    }
});

test('MNC-2 the main site blocks keep their existing routing', function () {
    expect(metricsConfigFile(METRICS_CADDYFILES['frankenphp-worker']))->toContain('index frankenphp-worker.php')
        ->and(metricsConfigFile(METRICS_CADDYFILES['frankenphp']))->toContain('import export_offload');
});

const METRICS_COMPOSE = '.ddev/docker-compose.metrics.yaml';
const METRICS_DASHBOARD = '.ddev/metrics/grafana/dashboards/frankenphp.json';

function metricsCompose(): array
{
    return Yaml::parse(metricsConfigFile(METRICS_COMPOSE));
}

function metricsDashboard(): array
{
    $dashboard = json_decode(metricsConfigFile(METRICS_DASHBOARD), true, flags: JSON_THROW_ON_ERROR);

    return $dashboard;
}

function metricsFixtureTypes(): array
{
    $names = [];

    foreach (glob(dirname(__DIR__).'/Fixtures/metrics/*.txt') as $file) {
        preg_match_all('/^# TYPE (\S+)/m', file_get_contents($file), $found);
        $names = array_merge($names, $found[1]);
    }

    return array_unique($names);
}

test('AC-2 the metrics services exist only under the metrics profile', function () {
    $services = metricsCompose()['services'];

    expect(array_keys($services))->toEqualCanonicalizing(['prometheus', 'grafana']);

    foreach ($services as $service) {
        expect($service['profiles'])->toBe(['metrics']);
    }
});

test('AC-2 the metrics command offers on, off and status', function () {
    $command = metricsConfigFile('.ddev/commands/host/metrics');

    expect($command)->toStartWith('#!/usr/bin/env bash')
        ->and($command)->toContain('--profiles=metrics')
        ->and($command)->toContain('docker stop "$PROMETHEUS" "$GRAFANA"')
        ->and($command)->toContain('/api/v1/targets')
        ->and($command)->toMatch('/^\s*on\)/m')
        ->and($command)->toMatch('/^\s*off\)/m')
        ->and($command)->toMatch('/^\s*status\)/m');
});

test('NFR-2 every image is pinned by digest', function () {
    foreach (metricsCompose()['services'] as $service) {
        expect($service['image'])->toMatch('/^[\w.\/-]+:[\w.-]+@sha256:[0-9a-f]{64}$/');
    }
});

test('NFR-2 the stack stays below 512 MB with a 1 s scrape and 2 days of retention', function () {
    $services = metricsCompose()['services'];
    $limits = collect($services)->map(fn (array $service) => $service['mem_limit']);

    expect($limits->every(fn ($limit) => preg_match('/^\d+m$/', $limit) === 1))->toBeTrue()
        ->and($limits->sum(fn (string $limit) => (int) $limit))->toBeLessThanOrEqual(512)
        ->and(implode(' ', $services['prometheus']['command']))->toContain('--storage.tsdb.retention.time=2d');

    $prometheus = Yaml::parse(metricsConfigFile('.ddev/metrics/prometheus.yml.template'));

    expect($prometheus['global']['scrape_interval'])->toBe('1s')
        ->and($prometheus['scrape_configs'][0]['static_configs'][0]['targets'])->toBe(['ddev-${DDEV_SITENAME}-web:9180']);
});

test('AC-2 the prometheus config is generated per project by ddev metrics on into a gitignored file', function () {
    $prometheus = metricsCompose()['services']['prometheus'];
    $command = metricsConfigFile('.ddev/commands/host/metrics');

    expect($prometheus['volumes'])->toContain('./metrics/prometheus.generated.yml:/etc/prometheus/prometheus.yml:ro')
        ->and($prometheus)->not->toHaveKey('entrypoint')
        ->and(metricsConfigFile('.gitignore'))->toContain('/.ddev/metrics/prometheus.generated.yml')
        ->and($command)->toContain('prometheus.yml.template')
        ->and($command)->toContain('prometheus.generated.yml')
        ->and($command)->toContain('SITE="${DDEV_SITENAME:?')
        ->and(strpos($command, 'write_prometheus_config'."\n".'    ddev start'))->not->toBeFalse();

    foreach ([METRICS_COMPOSE, '.ddev/metrics/prometheus.yml.template', '.ddev/metrics/grafana/provisioning/datasources/prometheus.yml', '.ddev/commands/host/metrics'] as $relative) {
        expect(metricsConfigFile($relative))->not->toContain('ai-lab');
    }
});

test('AC-3 grafana is provisioned and read-only for the anonymous viewer', function () {
    $environment = metricsCompose()['services']['grafana']['environment'];

    expect($environment)->toContain('GF_AUTH_ANONYMOUS_ENABLED=true')
        ->and($environment)->toContain('GF_AUTH_ANONYMOUS_ORG_ROLE=Viewer')
        ->and($environment)->toContain('GF_AUTH_DISABLE_LOGIN_FORM=true')
        ->and($environment)->toContain('GF_AUTH_DISABLE_LOGIN=true')
        ->and($environment)->toContain('GF_AUTH_BASIC_ENABLED=false')
        ->and($environment)->toContain('GF_USERS_ALLOW_SIGN_UP=false')
        ->and($environment)->toContain('GF_SERVER_ROOT_URL=https://${DDEV_HOSTNAME}:3030')
        ->and($environment)->toContain('DDEV_SITENAME=${DDEV_SITENAME}')
        ->and($environment)->toContain('HTTPS_EXPOSE=3030:3000');

    $datasource = Yaml::parse(metricsConfigFile('.ddev/metrics/grafana/provisioning/datasources/prometheus.yml'));
    $provider = Yaml::parse(metricsConfigFile('.ddev/metrics/grafana/provisioning/dashboards/dashboards.yml'));

    expect($datasource['datasources'][0]['url'])->toBe('http://ddev-${DDEV_SITENAME}-prometheus:9090')
        ->and($datasource['datasources'][0]['uid'])->toBe('prometheus')
        ->and($provider['providers'][0]['allowUiUpdates'])->toBeFalse();
});

test('AC-3 the dashboard parses, is not editable and shows every scope panel', function () {
    $dashboard = metricsDashboard();
    $titles = collect($dashboard['panels'])->pluck('title')->all();

    expect($dashboard['uid'])->toBe('frankenphp')
        ->and($dashboard['editable'])->toBeFalse()
        ->and($titles)->toEqualCanonicalizing([
            'Requests per second',
            'Latency p50 and p95',
            'Busy and total threads',
            'Busy, ready and total workers',
            'Queue depth',
            'Resident memory',
            'Requests per second by status code',
        ]);

    foreach ($dashboard['panels'] as $panel) {
        expect($panel['datasource']['uid'])->toBe('prometheus')
            ->and($panel['targets'])->not->toBeEmpty()
            ->and($panel['description'] ?? '')->not->toBe('');
    }

    $panels = collect($dashboard['panels'])->keyBy('title');

    expect($panels['Latency p50 and p95']['description'])->toContain('10 s')
        ->and($panels['Busy and total threads']['description'])->toContain('idle worker threads')
        ->and($panels['Queue depth']['description'])->toContain('not counted')
        ->and(collect($panels['Busy, ready and total workers']['targets'])->pluck('expr')->all())
        ->toBe(['frankenphp_busy_workers', 'frankenphp_ready_workers', 'frankenphp_total_workers']);
});

test('AC-3 the request panels count one handler per mode', function () {
    foreach (metricsDashboard()['panels'] as $panel) {
        foreach ($panel['targets'] as $target) {
            if (str_contains($target['expr'], 'caddy_http_request')) {
                expect($target['expr'])->toContain('{handler=~"php|vars|subroute"}');
            }
        }
    }
});

test('AC-3 every panel query names a metric found in the committed fixtures', function () {
    $known = metricsFixtureTypes();
    $used = [];

    foreach (metricsDashboard()['panels'] as $panel) {
        foreach ($panel['targets'] as $target) {
            preg_match_all('/\b((?:caddy|frankenphp|process)_[a-z_]+)/', $target['expr'], $found);
            $used = array_merge($used, $found[1]);
        }
    }

    expect($used)->not->toBeEmpty();

    foreach (array_unique($used) as $name) {
        $base = preg_replace('/_(bucket|sum|count)$/', '', $name);

        expect(in_array($name, $known, true) || in_array($base, $known, true))
            ->toBeTrue("{$name} is not in any committed fixture");
    }

    $workerFixture = file_get_contents(dirname(__DIR__).'/Fixtures/metrics/frankenphp-worker.txt');

    expect($used)->toContain('frankenphp_ready_workers')
        ->and($used)->toContain('frankenphp_busy_workers')
        ->and($workerFixture)->toContain('# TYPE frankenphp_ready_workers')
        ->and($workerFixture)->toContain('# TYPE frankenphp_busy_workers');
});

test('AC-6 no password or token is committed for grafana or prometheus', function () {
    $files = [METRICS_COMPOSE, '.ddev/metrics/prometheus.yml.template', METRICS_DASHBOARD,
        '.ddev/metrics/grafana/provisioning/datasources/prometheus.yml',
        '.ddev/metrics/grafana/provisioning/dashboards/dashboards.yml', '.ddev/commands/host/metrics'];

    foreach ($files as $relative) {
        expect(metricsConfigFile($relative))->not->toMatch('/password|secret|token|api_key|basic_auth/i');
    }
});

test('NFR-1 the metrics stack publishes only the grafana router port and never 9180 or 2019', function () {
    $compose = metricsConfigFile(METRICS_COMPOSE);
    $services = metricsCompose()['services'];

    expect($compose)->not->toMatch('/9180|2019/')
        ->and($services['prometheus'])->not->toHaveKeys(['ports', 'expose', 'environment'])
        ->and($services['grafana'])->not->toHaveKey('ports')
        ->and($services['grafana']['environment'])->not->toContain('HTTP_EXPOSE=3000:3000');
});

test('MNC-1 and MNC-4 the base ddev config is untouched and the stack is not in it', function () {
    expect(metricsConfigFile('.ddev/config.yaml'))->not->toMatch('/metrics|prometheus|grafana/i');
});

test('AC-1 AC-3 every mode fixture has the request counter and the latency histogram', function (string $mode) {
    $fixture = file_get_contents(dirname(__DIR__)."/Fixtures/metrics/{$mode}.txt");

    expect($fixture)->toContain('# TYPE caddy_http_requests_total counter')
        ->and($fixture)->toContain('# TYPE caddy_http_request_duration_seconds histogram')
        ->and($fixture)->toMatch('/^caddy_http_requests_total\{[^}]*\} \d+/m');
})->with(['frankenphp', 'frankenphp-worker', 'frankenphp-octane']);

test('AC-1 AC-3 the handler selector picks exactly one request series per mode, the one that counts every app request', function (string $mode) {
    $fixture = file_get_contents(dirname(__DIR__)."/Fixtures/metrics/{$mode}.txt");
    preg_match_all('/^caddy_http_requests_total\{([^}]*)\} (\d+)/m', $fixture, $series, PREG_SET_ORDER);

    $app = array_filter($series, fn (array $line): bool => ! str_contains($line[1], 'handler="metrics"'));
    $selected = array_filter($app, fn (array $line): bool => preg_match('/handler="(php|vars|subroute)"/', $line[1]) === 1);

    expect($app)->not->toBeEmpty()
        ->and($selected)->toHaveCount(1)
        ->and((int) array_values($selected)[0][2])->toBe(max(array_map(fn (array $line): int => (int) $line[2], $app)));
})->with(['frankenphp', 'frankenphp-worker', 'frankenphp-octane']);

test('AC-1 the fixtures were captured without a per-host label', function (string $mode) {
    expect(file_get_contents(dirname(__DIR__)."/Fixtures/metrics/{$mode}.txt"))->not->toContain('host="');
})->with(['frankenphp', 'frankenphp-worker', 'frankenphp-octane']);

test('AC-3 the status code panel groups a series that carries the code label on every mode', function (string $mode) {
    $panel = collect(metricsDashboard()['panels'])->firstWhere('title', 'Requests per second by status code');
    $expr = $panel['targets'][0]['expr'];

    expect($expr)->toContain('sum by (code)')
        ->and(preg_match('/\b(caddy_http_[a-z_]+)\{/', $expr, $metric))->toBe(1);

    $fixture = file_get_contents(dirname(__DIR__)."/Fixtures/metrics/{$mode}.txt");

    expect($fixture)->toMatch('/^'.$metric[1].'\{[^}]*code="\d{3}"[^}]*handler="(php|vars|subroute)"/m');
})->with(['frankenphp', 'frankenphp-worker', 'frankenphp-octane']);
