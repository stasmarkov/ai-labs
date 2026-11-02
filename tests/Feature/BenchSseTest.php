<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @param  list<int>  $times
 */
function sseLog(array $times): string
{
    $path = tempnam(sys_get_temp_dir(), 'sse-log-');
    $lines = array_map(
        fn (int $ms): string => $ms."\t".'data: {"type":"text_delta","delta":"word "}',
        $times,
    );
    file_put_contents($path, implode("\n", $lines).($lines === [] ? '' : "\n"));

    return $path;
}

/**
 * @param  list<string>  $flags
 */
function runSseAnalyse(string $log, array $flags = []): Process
{
    $process = new Process(['bash', base_path('bench/sse.sh'), ...$flags, '--analyse', $log]);
    $process->run();

    return $process;
}

test('AC-8 sse.sh reports timings and fails on buffering', function (): void {
    $spread = sseLog(array_map(fn (int $i): int => 20 + (int) round($i * 49.9), range(0, 199)));
    $ok = runSseAnalyse($spread);
    expect($ok->getExitCode())->toBe(0)
        ->and($ok->getOutput())->toContain('status=200', 'text_events=200', 'distinct_ms=200', 'first_text_ms=20');

    $buffered = sseLog(array_map(fn (int $i): int => 10000 + intdiv($i, 20), range(0, 199)));
    $bufferedRun = runSseAnalyse($buffered);
    expect($bufferedRun->getExitCode())->toBe(1)
        ->and($bufferedRun->getOutput())->toContain('first_text_ms=10000');

    $short = sseLog(array_map(fn (int $i): int => 20 + $i * 80, range(0, 119)));
    $shortRun = runSseAnalyse($short);
    expect($shortRun->getExitCode())->toBe(1)
        ->and($shortRun->getOutput())->toContain('text_events=120');

    $empty = runSseAnalyse(sseLog([]));
    expect($empty->getExitCode())->toBe(2);
});

test('AC-6 sse.sh --real checks a real model\'s stream', function (): void {
    $spread = fn (int $count, int $first, int $step): string => sseLog(
        array_map(fn (int $i): int => $first + $i * $step, range(0, $count - 1)),
    );

    $ok = runSseAnalyse($spread(40, 300, 40), ['--real']);
    expect($ok->getExitCode())->toBe(0)
        ->and($ok->getOutput())->toContain('status=200', 'text_events=40', 'first_text_ms=300');

    $reordered = new Process(['bash', base_path('bench/sse.sh'), '--analyse', $spread(40, 300, 40), '--real']);
    $reordered->run();
    expect($reordered->getExitCode())->toBe(0);

    expect(runSseAnalyse($spread(40, 5000, 40), ['--real'])->getExitCode())->toBe(0);

    expect(runSseAnalyse($spread(15, 300, 100), ['--real'])->getExitCode())->toBe(1);
    expect(runSseAnalyse($spread(40, 6000, 40), ['--real'])->getExitCode())->toBe(1);

    $clumped = sseLog(array_map(fn (int $i): int => 300 + intdiv($i, 4) * 150, range(0, 39)));
    expect(runSseAnalyse($clumped, ['--real'])->getExitCode())->toBe(1);

    expect(runSseAnalyse($spread(40, 300, 15), ['--real'])->getExitCode())->toBe(1);

    $empty = runSseAnalyse(sseLog([]), ['--real']);
    expect($empty->getExitCode())->toBe(2);

    expect(runSseAnalyse($spread(40, 300, 40))->getExitCode())->toBe(1);
});

test('AC-6 sse.sh --real ignores empty text deltas and labels its metric new_ms', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'sse-log-');
    $lines = ["10\tdata: {\"type\":\"text_delta\",\"delta\":\"\"}"];
    foreach (range(0, 39) as $i) {
        $lines[] = (300 + $i * 50)."\tdata: {\"type\":\"text_delta\",\"delta\":\"word \"}";
    }
    $lines[] = "2400\tdata: {\"type\":\"text_delta\",\"delta\":\"\"}";
    file_put_contents($path, implode("\n", $lines)."\n");

    $run = runSseAnalyse($path, ['--real']);

    expect($run->getExitCode())->toBe(0)
        ->and($run->getOutput())->toContain('first_text_ms=300', 'last_text_ms=2250', 'text_events=40', 'new_ms=40')
        ->not->toContain('distinct_ms');
});

test('AC-6 sse.sh refuses an argument that is not a listing id', function (string $argument): void {
    $process = new Process(['bash', base_path('bench/sse.sh'), $argument]);
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Usage:');
})->with(['--reel', 'abc', '0']);
