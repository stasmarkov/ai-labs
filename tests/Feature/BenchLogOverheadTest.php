<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bench-log-overhead-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/bin', 0777, true);
    mkdir($this->root.'/app');
    $this->envBefore = "APP_ENV=local\nLOG_STACK=single\nSECRET=keep\n";
    file_put_contents($this->root.'/app/.env', $this->envBefore);

    $stubs = [
        'ddev' => <<<'BASH'
#!/usr/bin/env bash
echo "ddev $*" >> "$STUB_LOG"
case "$*" in
  "server --default") echo frankenphp ;;
  "server --current") cat "$STUB_STATE" 2>/dev/null || echo frankenphp ;;
  "server frankenphp"*) echo "$2" > "$STUB_STATE" ;;
esac
BASH,
        'run' => <<<'BASH'
#!/usr/bin/env bash
echo "run $*" >> "$STUB_LOG"
echo "env $(grep -E '^(LOG_STACK|LOG_PING)=' "$BENCH_APPROOT/.env" | tr '\n' ' ')path=${BENCH_PING_PATH-}" >> "$STUB_LOG"
# Each run grows laravel.log, as the single channel does under load.
mkdir -p "$BENCH_APPROOT/storage/logs" && printf 'run line\n%.0s' 1 2 3 >> "$BENCH_APPROOT/storage/logs/laravel.log"
[[ "${STUB_RUN_EXIT-0}" == "0" ]] || exit "$STUB_RUN_EXIT"
label="${*: -1}"
case "$label" in
  log-off) p95=(10.0 11.0 12.0); ;;
  *) p95=(10.4 11.6 12.5); ;;
esac
echo "Wrote 3 result file(s) to $STUB_DIR (spread 1.0%)"
for i in 0 1 2; do
  echo "{\"p95_ms\": ${p95[$i]}, \"server\": \"frankenphp-worker\"}" > "$STUB_DIR/$label-$i.json"
  echo "$STUB_DIR/$label-$i.json"
done
BASH,
    ];
    foreach ($stubs as $name => $script) {
        file_put_contents($this->root.'/bin/'.$name, $script);
        chmod($this->root.'/bin/'.$name, 0755);
    }
    mkdir($this->root.'/runs');
    mkdir($this->root.'/tmp');

    $this->logOverhead = function (array $env = []): Process {
        $process = new Process(
            ['bash', base_path('bench/log-overhead.sh')],
            $this->root,
            $env + [
                'BENCH_APPROOT' => $this->root.'/app',
                'BENCH_RUN_SH' => $this->root.'/bin/run',
                'BENCH_DATE' => '2026-11-17',
                'STUB_LOG' => $this->root.'/calls.log',
                'STUB_STATE' => $this->root.'/server',
                'STUB_DIR' => $this->root.'/runs',
                'TMPDIR' => $this->root.'/tmp',
                'PATH' => $this->root.'/bin:'.getenv('PATH'),
            ],
        );
        $process->setTimeout(60)->run();

        return $process;
    };
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->root]))->run();
});

it('NFR-2 switches .env for each run, restores it and records both p95 values', function (): void {
    $process = ($this->logOverhead)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());

    $calls = file_get_contents($this->root.'/calls.log');
    expect($calls)->toContain('run ping --label log-off')
        ->and($calls)->toContain('run ping --label log-on')
        ->and($calls)->toContain('env LOG_STACK=single path=/debug/log-ping')
        ->and($calls)->toContain('env LOG_STACK=single,frankenphp path=/debug/log-ping')
        ->and($calls)->not->toContain('LOG_PING')
        ->and($calls)->toContain('ddev server frankenphp-worker');
    expect(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(glob($this->root.'/tmp/ai-lab-bench-log-env-backup-*'))->toBe([]);

    $result = json_decode((string) file_get_contents($this->root.'/app/bench/results/restart/2026-11-17/log-overhead.json'), true);
    expect($result['server'])->toBe('frankenphp-worker')
        ->and($result['route'])->toBe('/debug/log-ping')
        ->and($result['channel_off']['p95_ms'])->toBe(11.0)
        ->and($result['channel_on']['p95_ms'])->toBe(11.6)
        ->and($result['difference_percent'])->toBe(5.5)
        ->and($result['channel_on']['runs'])->toHaveCount(3);
});

it('F16 cuts laravel.log back to its size before the run and keeps the earlier entries', function (): void {
    mkdir($this->root.'/app/storage/logs', 0777, true);
    file_put_contents($this->root.'/app/storage/logs/laravel.log', "earlier entry\n");

    $process = ($this->logOverhead)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents($this->root.'/app/storage/logs/laravel.log'))->toBe("earlier entry\n");
});

it('F16 removes laravel.log when the run created it', function (): void {
    $process = ($this->logOverhead)(['STUB_RUN_EXIT' => '2']);

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_exists($this->root.'/app/storage/logs/laravel.log'))->toBeFalse();
});

it('F10 every .env backup name the bench scripts use is gitignored', function (): void {
    $ignored = fn (string $path): bool => (new Process(['git', 'check-ignore', '-q', $path], base_path()))->run() === 0;

    expect($ignored('.env.bench-backup'))->toBeTrue()
        ->and($ignored('.env.bench-log-backup'))->toBeTrue()
        ->and($ignored('.env.llm-load-backup'))->toBeTrue()
        ->and($ignored('.env.backup'))->toBeTrue();
});

it('NFR-2 restores .env and the default server when a run fails', function (): void {
    $process = ($this->logOverhead)(['STUB_RUN_EXIT' => '2']);

    expect($process->getExitCode())->not->toBe(0);
    expect(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore)
        ->and(glob($this->root.'/tmp/ai-lab-bench-log-env-backup-*'))->toBe([])
        ->and(file_get_contents($this->root.'/calls.log'))->toContain('ddev server frankenphp'."\n")
        ->and(file_exists($this->root.'/app/bench/results/restart/2026-11-17/log-overhead.json'))->toBeFalse();
});

it('NFR-2 refuses to start over a stale .env backup', function (): void {
    $sum = trim((new Process(['bash', '-c', 'printf %s "$1" | cksum | cut -d" " -f1', '_', $this->root.'/app']))->mustRun()->getOutput());
    file_put_contents($this->root.'/tmp/ai-lab-bench-log-env-backup-'.$sum, 'stale');

    $process = ($this->logOverhead)();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Stale')
        ->and(file_get_contents($this->root.'/app/.env'))->toBe($this->envBefore);
});
