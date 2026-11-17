<?php

declare(strict_types=1);

use App\Support\ReloadProbe;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-reload-'.bin2hex(random_bytes(4));
    $this->site = $this->root.'/app';
    $this->state = $this->root.'/state';
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->site.'/app/Support', 0777, true);
    mkdir($this->site.'/.ddev', 0777, true);
    mkdir($this->state, 0777, true);

    $this->probe = file_get_contents(base_path('app/Support/ReloadProbe.php'));
    $this->config = "name: interview\nwebserver_type: frankenphp\n";
    $this->env = "APP_ENV=local\nDEBUG_ROUTES=false\n";
    file_put_contents($this->site.'/app/Support/ReloadProbe.php', $this->probe);
    file_put_contents($this->site.'/.ddev/config.yaml', $this->config);
    file_put_contents($this->site.'/.env', $this->env);
    foreach (['servers/frankenphp-octane', 'servers/frankenphp-worker/web-build', 'web-build'] as $directory) {
        mkdir($this->site.'/.ddev/'.$directory, 0777, true);
    }
    $daemon = base_path('.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml');
    $caddyfile = base_path('.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp');
    copy($daemon, $this->site.'/.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml');
    copy($caddyfile, $this->site.'/.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp');
    file_put_contents($this->site.'/.ddev/config.zz-server.yaml', "# server: frankenphp-octane\n".file_get_contents($daemon));

    // The stub server reloads the probe on every request in classic mode and while watching; otherwise only when it
    // is switched, restarted or told to restart its workers, like the real modes do.
    file_put_contents($this->root.'/bin/ddev', <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
file="$STUB_APP/app/Support/ReloadProbe.php"
marker() { sed -n "s/.*MARKER = '\(.*\)';.*/\1/p" "$file"; }
snapshot() { marker > "$STUB_STATE/served"; }
case "$*" in
  "server --current") cat "$STUB_STATE/server" ;;
  "server --url") echo "https://stub.ddev.site" ;;
  "server --default") echo frankenphp ;;
  "server "*) echo "$2" > "$STUB_STATE/server"; echo off > "$STUB_STATE/watch"; rm -f "$STUB_APP"/.ddev/config.zzz-* "$STUB_APP"/.ddev/config.zzz-dev-watch.yaml; snapshot ;;
  "dev-watch status") echo "dev-watch: $(cat "$STUB_STATE/watch") (server: $(cat "$STUB_STATE/server"))" ;;
  "dev-watch on")
    # Like the real command: refused on Octane while a bench daemon overlay exists.
    if [[ "$(cat "$STUB_STATE/server")" == "frankenphp-octane" ]] && compgen -G "$STUB_APP/.ddev/config.zz*-bench-*.yaml" >/dev/null; then
      echo "refused: bench overlay" >> "$STUB_LOG"; exit 2
    fi
    echo on > "$STUB_STATE/watch"; snapshot ;;
  "dev-watch off") echo off > "$STUB_STATE/watch"; snapshot ;;
  "restart") echo "limit-lifted=$(grep -c 1000000 "$STUB_APP"/.ddev/config.zzz-bench-concurrency.yaml 2>/dev/null)" >> "$STUB_LOG"; snapshot ;;
  "artisan frankenphp:workers:restart") snapshot; echo "workers restarted in 42 ms" ;;
  "describe") echo "Perf mode: ${STUB_PERF:-mutagen}" ;;
  "exec frankenphp version") echo "FrankenPHP v1.13.5 PHP 8.4.1 Caddy v2.10.0" ;;
  "exec "*)
    # The in-container sync poll, run against the stub project; the lock lets curl see an exec running during a poll.
    touch "$STUB_STATE/exec-running"
    shift
    command="$*"
    bash -c "${command//\/var\/www\/html/$STUB_APP}"
    rm -f "$STUB_STATE/exec-running"
    ;;
esac
exit 0
BASH);
    file_put_contents($this->root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
file="$STUB_APP/app/Support/ReloadProbe.php"
[[ ! -e "$STUB_STATE/exec-running" ]] || echo "curl during exec" >> "$STUB_LOG"
if [[ "$(cat "$STUB_STATE/server")" == "frankenphp" || "$(cat "$STUB_STATE/watch")" == "on" ]]; then
  value="$(sed -n "s/.*MARKER = '\(.*\)';.*/\1/p" "$file")"
else
  value="$(cat "$STUB_STATE/served")"
fi
[[ -z "${STUB_SLOW:-}" ]] || sleep "$STUB_SLOW"
echo "{\"data\":{\"marker\":\"$value\"}}"
BASH);
    chmod($this->root.'/bin/ddev', 0755);
    chmod($this->root.'/bin/curl', 0755);

    $this->setStub = function (string $server, string $watch): void {
        file_put_contents($this->state.'/server', $server."\n");
        file_put_contents($this->state.'/watch', $watch."\n");
        file_put_contents($this->state.'/served', "initial\n");
    };
    $this->script = function (array $env = []): Process {
        return new Process(['bash', base_path('bench/reload-latency.sh')], $this->root, $env + [
            'BENCH_APPROOT' => $this->site,
            'BENCH_DATE' => '2026-11-17',
            'BENCH_TRIALS' => '2',
            'BENCH_NEVER_TRIALS' => '1',
            'BENCH_CAP_S' => '5',
            'BENCH_NEVER_CAP_S' => '1',
            'BENCH_SETTLE_S' => '0',
            'BENCH_JITTER_S' => '0',
            'BENCH_READY_TIMEOUT' => '5',
            'STUB_LOG' => $this->root.'/calls.log',
            'STUB_APP' => $this->site,
            'STUB_STATE' => $this->state,
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
        ]);
    };
    $this->calls = fn (): string => file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
    $this->result = fn (string $row): array => json_decode(
        file_get_contents($this->site.'/bench/results/reload/2026-11-17/'.$row.'.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-6 the script records the five rows with statistics, never counts, restart times and the Mutagen delay', function (): void {
    ($this->setStub)('frankenphp-worker', 'on');
    $process = ($this->script)();
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $classic = ($this->result)('classic');
    expect($classic['mode'])->toBe('frankenphp')
        ->and($classic['watch'])->toBeFalse()
        ->and($classic['trials'])->toHaveCount(2)
        ->and($classic['never'])->toBe(0)
        ->and($classic['median_ms'])->toBeNumeric()
        ->and($classic['p90_ms'])->toBeGreaterThanOrEqual($classic['median_ms'])
        ->and($classic['max_ms'])->toBeGreaterThanOrEqual($classic['p90_ms'])
        ->and($classic['restart_ms'])->toBeNull()
        ->and($classic['trials'][0]['mutagen_ms'])->toBeInt()
        ->and($classic['mutagen_median_ms'])->not->toBeNull()
        ->and($classic['frankenphp_version'])->toBe('v1.13.5')
        ->and($classic)->toHaveKeys(['commit', 'trials', 'median_ms', 'p90_ms', 'max_ms', 'never', 'restart_ms']);

    foreach (['octane-nowatch' => 'frankenphp-octane', 'worker-nowatch' => 'frankenphp-worker'] as $row => $mode) {
        $result = ($this->result)($row);
        expect($result['mode'])->toBe($mode)
            ->and($result['watch'])->toBeFalse()
            ->and($result['trials'])->toHaveCount(1)
            ->and($result['never'])->toBe(1)
            ->and($result['median_ms'])->toBeNull()
            ->and($result['p90_ms'])->toBeNull()
            ->and($result['max_ms'])->toBeNull()
            ->and($result['restart_ms'])->toHaveKeys(['median', 'p90', 'max'])
            ->and($result['restart_ms']['median'])->toBeNumeric()
            ->and($result['restart_call_ms'])->toBe(['median' => 42, 'p90' => 42, 'max' => 42]);
    }

    foreach (['octane-watch' => 'frankenphp-octane', 'worker-watch' => 'frankenphp-worker'] as $row => $mode) {
        $result = ($this->result)($row);
        expect($result['mode'])->toBe($mode)
            ->and($result['watch'])->toBeTrue()
            ->and($result['trials'])->toHaveCount(2)
            ->and($result['never'])->toBe(0)
            ->and($result['median_ms'])->toBeNumeric()
            ->and($result['restart_ms'])->toBeNull()
            ->and($result['restart_call_ms'])->toBeNull();
    }

    // AC-3 and F1: the no-watch rows lift the request limit; every row starts with `ddev server`, so the watch rows run
    // `dev-watch on` with no bench overlay left (on Octane it would be refused).
    expect(($this->calls)())->toMatch('/limit-lifted=1\n.*limit-lifted=2\n/s')
        ->and(($this->calls)())->toContain("ddev server frankenphp-octane\nddev dev-watch on\n", "ddev server frankenphp-worker\nddev dev-watch on\n")
        ->and(($this->calls)())->not->toContain('refused')
        ->and(substr_count(($this->calls)(), 'ddev server frankenphp-octane'))->toBe(2)
        ->and($this->site.'/.ddev/config.zzz-bench-concurrency.yaml')->not->toBeFile()
        // F11: the Mutagen pass runs after each timed trial, never while the probe route is being polled.
        ->and(($this->calls)())->not->toContain('curl during exec')
        ->and(substr_count(($this->calls)(), 'ddev exec m='))->toBe(3 * 2 + 2 * 1 + 2 * 2);
});

it('AC-6 the Mutagen delay is n/a when ddev does not run Mutagen', function (): void {
    ($this->setStub)('frankenphp', 'off');
    $process = ($this->script)(['STUB_PERF' => 'none', 'BENCH_ROWS' => 'classic']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(($this->result)('classic')['trials'][0]['mutagen_ms'])->toBe('n/a')
        ->and(($this->result)('classic')['mutagen_median_ms'])->toBe('n/a')
        ->and(($this->calls)())->not->toContain('ddev exec m=')
        ->and($this->site.'/bench/results/reload/2026-11-17/octane-watch.json')->not->toBeFile();
});

it('F10 the caps take seconds with decimals and refuse anything else before touching the project', function (): void {
    ($this->setStub)('frankenphp', 'off');
    $process = ($this->script)(['BENCH_NEVER_CAP_S' => 'soon']);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Not a number of seconds: soon')
        ->and(($this->calls)())->toBe('')
        ->and(file_get_contents($this->site.'/app/Support/ReloadProbe.php'))->toBe($this->probe);
});

it('BENCH_ROWS that names no row is refused', function (): void {
    ($this->setStub)('frankenphp', 'off');
    $process = ($this->script)(['BENCH_ROWS' => 'sideways']);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('BENCH_ROWS names no known row');
});

it('NFR-1 the probe file, .env, server and dev-watch state are back after a run', function (string $server, string $watch): void {
    ($this->setStub)($server, $watch);
    $process = ($this->script)(['BENCH_ROWS' => 'classic,worker-watch']);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->site.'/app/Support/ReloadProbe.php'))->toBe($this->probe)
        ->and(file_get_contents($this->site.'/.env'))->toBe($this->env)
        ->and($this->site.'/.env.bench-backup')->not->toBeFile()
        ->and(file_get_contents($this->site.'/.ddev/config.yaml'))->toBe($this->config)
        ->and(trim(file_get_contents($this->state.'/server')))->toBe($server)
        ->and(trim(file_get_contents($this->state.'/watch')))->toBe($watch);
})->with([
    'worker with watch' => ['frankenphp-worker', 'on'],
    'octane without watch' => ['frankenphp-octane', 'off'],
    'classic' => ['frankenphp', 'off'],
]);

it('NFR-1 a probe file that differs from git after the restore fails the run with a message', function (): void {
    ($this->setStub)('frankenphp', 'off');
    $git = fn (string ...$args) => (new Process(['git', '-C', $this->site, '-c', 'user.name=t', '-c', 'user.email=t@t', ...$args]))->mustRun();
    $git('init', '-q');
    file_put_contents($this->site.'/app/Support/ReloadProbe.php', $this->probe."// committed\n");
    $git('add', 'app/Support/ReloadProbe.php');
    $git('commit', '-q', '-m', 'probe');
    file_put_contents($this->site.'/app/Support/ReloadProbe.php', $this->probe);

    $process = ($this->script)(['BENCH_ROWS' => 'classic']);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('ReloadProbe.php differs from git');
});

it('NFR-1 an interrupt in the middle of a trial restores the probe file, .env, server and watch state', function (): void {
    ($this->setStub)('frankenphp-worker', 'on');
    $process = ($this->script)(['BENCH_CAP_S' => '30', 'STUB_SLOW' => '0.05', 'BENCH_ROWS' => 'classic,octane-watch']);
    $process->setTimeout(60);
    $process->start();

    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline) {
        $probe = file_get_contents($this->site.'/app/Support/ReloadProbe.php');
        if ($probe !== $this->probe && str_contains(($this->calls)(), 'ddev server frankenphp-octane')) {
            break;
        }
        usleep(50_000);
    }
    expect(file_get_contents($this->site.'/.env'))->toContain('DEBUG_ROUTES=true');

    $process->signal(SIGINT);
    $process->wait();

    expect($process->getExitCode())->toBe(130)
        ->and(file_get_contents($this->site.'/app/Support/ReloadProbe.php'))->toBe($this->probe)
        ->and(file_get_contents($this->site.'/.env'))->toBe($this->env)
        ->and($this->site.'/.env.bench-backup')->not->toBeFile()
        ->and(trim(file_get_contents($this->state.'/server')))->toBe('frankenphp-worker')
        ->and(trim(file_get_contents($this->state.'/watch')))->toBe('on');
});

it('NFR-1 a stale .env.bench-backup stops the run before any server switch and leaves the probe file alone', function (): void {
    ($this->setStub)('frankenphp', 'off');
    file_put_contents($this->site.'/.env.bench-backup', 'stale');
    $process = ($this->script)();
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Stale')
        ->and(($this->calls)())->not->toContain('ddev server frankenphp-octane')
        ->and(file_get_contents($this->site.'/app/Support/ReloadProbe.php'))->toBe($this->probe)
        ->and(file_get_contents($this->site.'/.env.bench-backup'))->toBe('stale');
});

it('NFR-1 the script never touches .ddev/config.yaml or writes an overlay itself', function (): void {
    $script = file_get_contents(base_path('bench/reload-latency.sh'));

    expect($script)->not->toContain('config.yaml')
        ->and($script)->not->toContain('config.zz')
        ->and($script)->not->toContain('exec cat');
});

it('NFR-1 the probe route answers the marker while debug routes are on and a plain 404 while they are off', function (): void {
    $this->getJson('/debug/reload-probe')->assertNotFound();
    $this->postJson('/debug/reload-probe')->assertNotFound();

    config(['app.debug_routes' => true]);

    $this->getJson('/debug/reload-probe')->assertOk()->assertJsonPath('data.marker', ReloadProbe::MARKER);
    $this->postJson('/debug/reload-probe')->assertStatus(405);
});

it('MNC-4 the committed probe marker is initial', function (): void {
    expect(ReloadProbe::MARKER)->toBe('initial');
});
