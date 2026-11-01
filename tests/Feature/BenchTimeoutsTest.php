<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-timeouts-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app/.ddev/servers/openlitespeed/conf', 0777, true);
    copy(base_path('.ddev/servers/openlitespeed/conf/httpd_config.conf'), $this->root.'/app/.ddev/servers/openlitespeed/conf/httpd_config.conf');
    file_put_contents($this->root.'/app/.ddev/config.yaml', "name: interview\n");
    mkdir($this->root.'/app/.ddev/nginx_full', 0777, true);
    $this->nginxSite = "#ddev-generated\nserver {\n    location ~ \\.php$ {\n        fastcgi_read_timeout 10m;\n    }\n}\n";
    file_put_contents($this->root.'/app/.ddev/nginx_full/nginx-site.conf', $this->nginxSite);
    file_put_contents($this->root.'/bin/docker', <<<'BASH'
#!/usr/bin/env bash
echo "docker $*" >> "$STUB_LOG"
if [[ "$1" == "logs" && -z "${STUB_NO_SERVER_LOG-}" ]]; then
  echo "++ echo 'request_terminate_timeout = 10s'"
  echo "upstream timed out (110: Connection timed out)"
fi
if [[ "$1" == "exec" ]]; then
  case "$*" in
    *"wc -c"*) echo 0 ;;
    *"tail -c"*) [[ -n "${STUB_APP_LOG-}" ]] && echo "$STUB_APP_LOG" ;;
  esac
fi
BASH);
    chmod($this->root.'/bin/docker', 0755);
    file_put_contents($this->root.'/app/.env', "APP_ENV=local\nDEBUG_ROUTES=false\n");

    file_put_contents($this->root.'/bin/ddev', <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$1 $2" in
  "server --current") echo "$STUB_SERVER" ;;
  "server --url") echo "https://interview.ddev.site" ;;
esac
if [[ "$1" == "logs" ]]; then
  echo "upstream timed out (110: Connection timed out)"
fi
if [[ "$1 $2" == "artisan optimize:clear" ]]; then
  echo "env during optimize: $(grep DEBUG_ROUTES "$BENCH_APPROOT/.env")" >> "$STUB_LOG"
fi
BASH);
    file_put_contents($this->root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    --max-time|-w) shift 2 ;;
    *) shift ;;
  esac
done
echo "DEBUG_ROUTES seen: $(grep DEBUG_ROUTES "$BENCH_APPROOT/.env")" >> "$STUB_LOG"
echo '{"message":"gateway"}' > "$out"
printf '%s\n' "${STUB_CURL_RESULT:-504 10.2 nginx}"
BASH);
    chmod($this->root.'/bin/ddev', 0755);
    chmod($this->root.'/bin/curl', 0755);

    $this->extraEnv = [];
    $this->timeouts = function (string $server, string ...$args): Process {
        $process = new Process(
            ['bash', base_path('bench/timeouts.sh'), ...$args],
            $this->root,
            $this->extraEnv + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_RESULTS_DIR' => $this->root.'/results',
                'STUB_SERVER' => $server,
                'STUB_LOG' => $this->root.'/calls.log',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->run();

        return $process;
    };
    $this->overlayFiles = fn (): array => array_map(
        fn (string $path): string => substr($path, strlen($this->root.'/app/.ddev/')),
        array_filter(
            array_merge(
                glob($this->root.'/app/.ddev/php/*') ?: [],
                glob($this->root.'/app/.ddev/nginx/*') ?: [],
                glob($this->root.'/app/.ddev/web-entrypoint.d/*') ?: [],
                glob($this->root.'/app/.ddev/docker-compose.bench*') ?: [],
                glob($this->root.'/app/.ddev/bench-timeouts/*') ?: [],
                glob($this->root.'/app/.ddev/nginx_full/*.bench-orig') ?: [],
                // The site config counts as an overlay only while the bench has replaced it.
                str_contains((string) file_get_contents($this->root.'/app/.ddev/nginx_full/nginx-site.conf'), '#ddev-generated')
                    ? [] : [$this->root.'/app/.ddev/nginx_full/nginx-site.conf'],
            ),
        ),
    );
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-9 MNC-4 timeouts apply short writes an overlay for every layer and defaults removes them', function (string $server, array $expected): void {
    $short = ($this->timeouts)($server, 'apply', 'short');

    expect($short->getExitCode())->toBe(0, $short->getErrorOutput());

    $files = ($this->overlayFiles)();
    foreach ($expected as $file => $needles) {
        expect($files)->toContain($file)
            ->and(file_get_contents($this->root.'/app/.ddev/'.$file))->toContain(...$needles);
    }

    $defaults = ($this->timeouts)($server, 'apply', 'defaults');

    expect($defaults->getExitCode())->toBe(0, $defaults->getErrorOutput())
        ->and(($this->overlayFiles)())->toBe([])
        ->and(file_get_contents($this->root.'/app/.ddev/config.yaml'))->toBe("name: interview\n")
        ->and(file_get_contents($this->root.'/app/.ddev/nginx_full/nginx-site.conf'))->toBe($this->nginxSite);
})->with([
    'nginx-fpm' => ['nginx-fpm', [
        'php/zz-bench-timeouts.ini' => ['max_execution_time = 10'],
        'web-entrypoint.d/zz-bench-timeouts.sh' => ['request_terminate_timeout = 10s'],
        'nginx_full/nginx-site.conf' => ['fastcgi_read_timeout 10s'],
    ]],
    'apache-fpm' => ['apache-fpm', [
        'php/zz-bench-timeouts.ini' => ['max_execution_time = 10'],
        'web-entrypoint.d/zz-bench-timeouts.sh' => ['request_terminate_timeout = 10s', 'ProxyTimeout 10'],
    ]],
    'openlitespeed' => ['openlitespeed', [
        'docker-compose.bench-timeouts.yaml' => ['bench-timeouts/httpd_config.conf', 'zz-timezone.ini'],
        'bench-timeouts/httpd_config.conf' => ['connTimeout             10', 'initTimeout             10'],
        'bench-timeouts/zz-timezone.ini' => ['max_execution_time=10'],
    ]],
    'frankenphp' => ['frankenphp', [
        'web-entrypoint.d/zz-bench-timeouts.sh' => ['max_execution_time = 10'],
    ]],
    'frankenphp-worker' => ['frankenphp-worker', [
        'web-entrypoint.d/zz-bench-timeouts.sh' => ['max_execution_time = 10'],
    ]],
]);

it('AC-9 MNC-4 timeouts run switches DEBUG_ROUTES, writes a result file and restores .env and overlays', function (): void {
    $before = file_get_contents($this->root.'/app/.env');

    $run = ($this->timeouts)('nginx-fpm', 'run');

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($before)
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse()
        ->and(($this->overlayFiles)())->toBe([])
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('DEBUG_ROUTES seen: DEBUG_ROUTES=true');

    $files = glob($this->root.'/results/*/timeouts-nginx-fpm.json');
    expect($files)->toHaveCount(1);

    $result = json_decode(file_get_contents($files[0]), true);
    expect($result['server'])->toBe('nginx-fpm')
        ->and($result['router_timeout'])->toContain('default')
        ->and($result['runs'])->toHaveCount(6)
        ->and($result['runs'][0]['status'])->toBe(504)
        ->and($result['runs'][0]['ending_layer'])->toBe('nginx fastcgi_read_timeout')
        ->and(array_values(array_unique(array_column($result['runs'], 'label'))))->toBe(['defaults', 'short']);
});

it('AC-9 MNC-4 timeouts run restores .env when a measurement fails', function (): void {
    unlink($this->root.'/bin/curl');
    file_put_contents($this->root.'/bin/curl', "#!/usr/bin/env bash\nexit 7\n");
    chmod($this->root.'/bin/curl', 0755);
    $before = file_get_contents($this->root.'/app/.env');

    ($this->timeouts)('nginx-fpm', 'run');

    expect(file_get_contents($this->root.'/app/.env'))->toBe($before)
        ->and(($this->overlayFiles)())->toBe([]);
});

it('AC-9 a timeout seen only in Laravel\'s own log is attributed to max_execution_time', function (): void {
    $this->extraEnv = [
        'STUB_NO_SERVER_LOG' => '1',
        'STUB_CURL_RESULT' => '500 10.0 LiteSpeed',
        'STUB_APP_LOG' => '[2026-11-01 10:10:10] production.ERROR: Maximum execution time of 10 seconds exceeded',
    ];

    $run = ($this->timeouts)('openlitespeed', 'run');

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput());

    $result = json_decode(file_get_contents(glob($this->root.'/results/*/timeouts-openlitespeed.json')[0]), true);

    expect($result['runs'][0]['ending_layer'])->toBe('PHP max_execution_time')
        ->and($result['runs'][0]['log_excerpt'])->toContain('Maximum execution time');
});

it('AC-9 an OpenLiteSpeed 500 without a PHP fatal is attributed to the external app timeout', function (): void {
    $this->extraEnv = ['STUB_NO_SERVER_LOG' => '1', 'STUB_CURL_RESULT' => '500 10.9 LiteSpeed'];

    $run = ($this->timeouts)('openlitespeed', 'run');
    $result = json_decode(file_get_contents(glob($this->root.'/results/*/timeouts-openlitespeed.json')[0]), true);

    expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
        ->and($result['runs'][3]['ending_layer'])->toBe('OpenLiteSpeed external app timeout');
});
