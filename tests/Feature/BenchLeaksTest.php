<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-leaks-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app/.ddev/servers/frankenphp-octane', 0777, true);
    copy(base_path('.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml'), $this->root.'/app/.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml');
    $this->envBefore = "APP_ENV=local\nDEBUG_ROUTES=false\n";
    file_put_contents($this->root.'/app/.env', $this->envBefore);

    file_put_contents($this->root.'/bin/ddev', <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$1 $2" in
  "server --current") echo "$STUB_SERVER" ;;
  "server --url") echo "https://ai-lab.ddev.site" ;;
esac
if [[ "$1" == "restart" ]]; then
  echo "overlay during restart: $(cat "$BENCH_APPROOT/.ddev/config.zzz-bench-leaks.yaml" 2>/dev/null | tr '\n' ' ')" >> "$STUB_LOG"
fi
BASH);
    file_put_contents($this->root.'/bin/curl', <<<'BASH'
#!/usr/bin/env bash
while [[ $# -gt 0 ]]; do
  case "$1" in
    -o) out="$2"; shift 2 ;;
    --max-time|-w|-H) shift 2 ;;
    -*) shift ;;
    *) url="$1"; shift ;;
  esac
done
echo "DEBUG_ROUTES seen: $(grep DEBUG_ROUTES "$BENCH_APPROOT/.env")" >> "$STUB_LOG"
if [[ -n "${STUB_STATUS-}" ]]; then
  echo '{"message":"Not Found"}' > "$out"
  echo "$STUB_STATUS"
  exit 0
fi
leaked=false
[[ "$url" == *price-cache* && "$url" == *mode=leak* && "$url" == *dealer=2* ]] && leaked=true
# STUB_HTML_ONE: the 1st B response of price-cache/leak is an HTML error page (one bad body in a row).
if [[ -n "${STUB_HTML_ONE-}" && "$leaked" == true && "$out" == */1.json ]]; then
  echo "<html>502 Bad Gateway</html>" > "$out"
  echo 502
  exit 0
fi
# STUB_A_FAILS: every A request (dealer=1) fails with a 500.
if [[ -n "${STUB_A_FAILS-}" && "$url" == *dealer=1* ]]; then
  echo '{"success":false}' > "$out"
  echo 500
  exit 0
fi
echo "{\"success\":true,\"data\":{\"leaked\":$leaked}}" > "$out"
echo 200
BASH);
    chmod($this->root.'/bin/ddev', 0755);
    chmod($this->root.'/bin/curl', 0755);

    $this->leaks = function (string $server, array $args = [], array $env = []): Process {
        $process = new Process(
            ['bash', base_path('bench/leaks.sh'), ...$args],
            $this->root,
            [
                'BENCH_APPROOT' => $this->root.'/app',
                'STUB_SERVER' => $server,
                'STUB_LOG' => $this->root.'/calls.log',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
                ...$env,
            ],
        );
        $process->run();

        return $process;
    };
    $this->calls = fn (): string => file_exists($this->root.'/calls.log') ? file_get_contents($this->root.'/calls.log') : '';
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('AC-8 leaks.sh prints a count per scenario and mode and restores .env', function (): void {
    $process = ($this->leaks)('frankenphp');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $lines = array_values(array_filter(explode("\n", $process->getOutput()), fn (string $line): bool => str_contains($line, 'leaked ')));

    expect($lines)->toHaveCount(8)
        ->and($lines[0])->toMatch('/^dealer-scope\s+leak\s+leaked 0\/20$/')
        ->and($lines[1])->toMatch('/^dealer-scope\s+fixed\s+leaked 0\/20$/')
        ->and($lines[2])->toMatch('/^price-cache\s+leak\s+leaked 20\/20$/')
        ->and($lines[3])->toMatch('/^price-cache\s+fixed\s+leaked 0\/20$/')
        ->and($lines[4])->toMatch('/^number-locale\s+leak\s+leaked 0\/20$/')
        ->and($lines[7])->toMatch('/^request-at-boot\s+fixed\s+leaked 0\/20$/')
        ->and($process->getOutput())->toContain('frankenphp')
        ->and(($this->calls)())->toContain('DEBUG_ROUTES seen: DEBUG_ROUTES=true')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse();
});

it('AC-8 leaks.sh sends 20 requests as dealer 1 then 20 as dealer 2 per row', function (): void {
    ($this->leaks)('frankenphp');

    expect(substr_count(($this->calls)(), 'DEBUG_ROUTES seen'))->toBeGreaterThanOrEqual(8 * 40);
});

it('AC-8 leaks.sh restarts the workers of the active server before every row', function (string $server, string $command, int $minimum): void {
    $process = ($this->leaks)($server);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(substr_count(($this->calls)(), $command))->toBeGreaterThanOrEqual($minimum);
})->with([
    // One reload per row (8); the .env switch itself restarts the Octane daemon instead (see the next test).
    'frankenphp-octane' => ['frankenphp-octane', 'ddev artisan octane:reload --server=frankenphp', 8],
    // One restart per row plus the .env switch and its restore.
    'frankenphp-worker' => ['frankenphp-worker', 'ddev exec curl -sf -X POST localhost:2019/frankenphp/workers/restart', 9],
]);

it('AC-8 leaks.sh restarts the Octane daemon after switching .env, because a reload keeps the old env', function (): void {
    $process = ($this->leaks)('frankenphp-octane');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(substr_count(($this->calls)(), 'ddev restart'))->toBeGreaterThanOrEqual(2);
});

it('AC-8 leaks.sh refuses when the debug routes stay disabled and restores .env', function (): void {
    $process = ($this->leaks)('frankenphp', [], ['STUB_STATUS' => '404', 'BENCH_SETTLE' => '1']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('DEBUG_ROUTES')
        ->and($process->getOutput())->not->toContain('leaked ')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(file_exists($this->root.'/app/.env.bench-backup'))->toBeFalse();
});

it('AC-8 leaks.sh refuses to run over a stale .env backup', function (): void {
    file_put_contents($this->root.'/app/.env.bench-backup', "APP_ENV=old\n");

    $process = ($this->leaks)('frankenphp');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Stale')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);
});

it('AC-8 leaks.sh --max-requests writes the overlay for the restart and removes it on exit', function (): void {
    $process = ($this->leaks)('frankenphp-octane', ['--max-requests', '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('max-requests 1')
        ->and(($this->calls)())->toContain('overlay during restart: override_config: true')
        ->and(($this->calls)())->toContain('--max-requests=1 ')
        ->and(($this->calls)())->not->toContain('--max-requests=500 --log-level=warn" directory: /var/www/html overlay')
        ->and(substr_count(($this->calls)(), 'ddev restart'))->toBe(2)
        ->and(glob($this->root.'/app/.ddev/config.zzz-bench-leaks.yaml'))->toBe([])
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);
});

it('AC-8 leaks.sh --max-requests is refused on servers without the Octane daemon', function (): void {
    $process = ($this->leaks)('frankenphp', ['--max-requests', '1']);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('frankenphp-octane')
        ->and(($this->calls)())->not->toContain('ddev restart')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);
});

it('AC-8 leaks.sh rejects values that are not positive integers', function (string $value): void {
    $process = ($this->leaks)('frankenphp-octane', ['--max-requests', $value]);

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage');
})->with(['zero' => '0', 'text' => 'many']);

it('AC-8 one HTML error body does not zero the leak count of its row', function (): void {
    $process = ($this->leaks)('frankenphp-octane', [], ['STUB_HTML_ONE' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toMatch('/price-cache\s+leak\s+leaked 19\/20 \(1 errors\)/');
});

it('AC-8 a failed A phase is reported instead of reading as a clean row', function (): void {
    $process = ($this->leaks)('frankenphp-octane', [], ['STUB_A_FAILS' => '1']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('A phase: 20 errors');
});
