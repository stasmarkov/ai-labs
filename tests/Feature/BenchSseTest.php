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

function runSseAnalyse(string $log): Process
{
    $process = new Process(['bash', base_path('bench/sse.sh'), '--analyse', $log]);
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
