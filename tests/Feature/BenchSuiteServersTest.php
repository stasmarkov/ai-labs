<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bench-suite-'.bin2hex(random_bytes(4));
    mkdir($this->dir.'/bin', 0777, true);
    mkdir($this->dir.'/bench', 0777, true);
    $this->log = $this->dir.'/log';
    touch($this->log);
    file_put_contents($this->dir.'/current', "nginx-fpm\n");

    copy(base_path('bench/suite.sh'), $this->dir.'/bench/suite.sh');

    $stub = function (string $path, string $body): void {
        file_put_contents($path, "#!/usr/bin/env bash\n".$body);
        chmod($path, 0755);
    };

    $stub($this->dir.'/bin/ddev', <<<'SH'
if [[ "$*" == "server --current" ]]; then cat "$BENCH_STUB_DIR/current"; exit 0; fi
if [[ "$1" == "server" ]]; then echo "$2" > "$BENCH_STUB_DIR/current"; echo "ddev server $2" >> "$BENCH_STUB_DIR/log"; fi
exit 0
SH);
    $stub($this->dir.'/bench/concurrency.sh', 'echo "concurrency $*" >> "$BENCH_STUB_DIR/log"'."\n");
    $stub($this->dir.'/bench/timeouts.sh', 'echo "timeouts $*" >> "$BENCH_STUB_DIR/log"'."\n");
    $stub($this->dir.'/bench/pick-tuned.sh', "echo 8\n");
    $stub($this->dir.'/bench/run.sh', <<<'SH'
scenario="$1"; label=""
while [[ $# -gt 0 ]]; do [[ "$1" == "--label" ]] && label="$2"; shift; done
server="$(cat "$BENCH_STUB_DIR/current")"
dir="$BENCH_APPROOT/bench/results/raw/$BENCH_DATE/$server"
mkdir -p "$dir"
sleep 0.05
echo '{}' > "$dir/$scenario-$label-1.json"
echo "run $server $scenario $label" >> "$BENCH_STUB_DIR/log"
SH);
    file_put_contents($this->dir.'/bench/summary.php', "<?php\nfile_put_contents(getenv('BENCH_STUB_DIR').'/log', 'summary '.implode(' ', array_map(fn (\$a) => preg_replace('~[^\\s=]*/~', '', \$a), array_slice(\$argv, 1)))\n.\"\\n\", FILE_APPEND);\n");

    $this->suite = function (array $arguments) {
        $process = new Process(['bash', $this->dir.'/bench/suite.sh', ...$arguments], null, [
            'PATH' => $this->dir.'/bin:'.getenv('PATH'),
            'BENCH_STUB_DIR' => $this->dir,
            'BENCH_APPROOT' => $this->dir,
            'BENCH_DATE' => '2030-01-01',
        ]);
        $process->run();

        return $process;
    };
    $this->log = fn (): array => array_values(array_filter(explode("\n", (string) file_get_contents($this->dir.'/log'))));
});

afterEach(function (): void {
    (new Process(['rm', '-rf', $this->dir]))->run();
});

it('AC-2 suite runs only the listed servers with frankenphp as the baseline', function (): void {
    $process = ($this->suite)(['--quick', '--servers', 'frankenphp-worker,frankenphp,frankenphp-octane']);
    $log = ($this->log)();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    $ran = array_values(array_unique(array_map(fn (string $line): string => explode(' ', $line)[1], array_filter($log, fn (string $line): bool => str_starts_with($line, 'run ')))));
    expect($ran)->toBe(['frankenphp', 'frankenphp-worker', 'frankenphp-octane']);

    $summaries = array_values(array_filter($log, fn (string $line): bool => str_starts_with($line, 'summary ')));
    expect($summaries)->toContain('summary ping-fair-1.json --self-baseline --write=frankenphp-ping-fair.json')
        ->toContain('summary ping-fair-1.json --baseline=frankenphp-ping-fair.json --write=frankenphp-worker-ping-fair.json')
        ->toContain('summary search-tuned-1.json --baseline=frankenphp-search-tuned.json --write=frankenphp-octane-search-tuned.json');
});

it('AC-2 suite skips the timeout step in --servers runs', function (): void {
    ($this->suite)(['--servers', 'frankenphp,frankenphp-octane,frankenphp-worker']);
    $log = ($this->log)();

    expect(array_values(array_filter($log, fn (string $line): bool => $line === 'timeouts run')))->toHaveCount(0);
});

it('AC-2 suite prefers nginx-fpm as the baseline and runs it first', function (): void {
    ($this->suite)(['--servers', 'frankenphp,nginx-fpm']);
    $log = ($this->log)();

    expect(array_values(array_filter($log, fn (string $line): bool => str_starts_with($line, 'ddev server ')))[0])->toBe('ddev server nginx-fpm')
        ->and($log)->toContain('summary ping-fair-1.json --baseline=nginx-fpm-ping-fair.json --write=frankenphp-ping-fair.json');
});

it('AC-2 suite rejects a list without a baseline server and unknown names', function (): void {
    expect(($this->suite)(['--servers', 'frankenphp-octane'])->getExitCode())->toBe(2)
        ->and(($this->suite)(['--servers', 'frankenphp,bogus'])->getExitCode())->toBe(2)
        ->and(($this->log)())->toBe([]);
});

it('MNC-1 suite without --servers runs the default four classic servers in order', function (): void {
    ($this->suite)(['--quick']);
    $log = ($this->log)();

    $servers = array_values(array_filter(array_map(fn (string $line): string => str_starts_with($line, 'ddev server ') ? substr($line, 12) : '', $log)));

    expect(array_slice($servers, 0, 4))->toBe(['nginx-fpm', 'apache-fpm', 'openlitespeed', 'frankenphp'])
        ->and(array_values(array_filter($log, fn (string $line): bool => $line === 'timeouts run')))->toHaveCount(4)
        ->and($log)->toContain('summary ping-fair-1.json --baseline=nginx-fpm-ping-fair.json --write=apache-fpm-ping-fair.json');
});
