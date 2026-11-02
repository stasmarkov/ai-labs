<?php

declare(strict_types=1);

/**
 * Summary math for benchmark result files (plain PHP, no Laravel).
 *
 * Usage: php bench/summary.php <result-dir-or-file>... [--baseline=<dir-or-file>...] [--self-baseline] [--write=<file>]
 * Prints one BENCHMARKS.md table row per scenario/label/server group.
 * With --write=<file> it also writes the committed summary JSON (a --baseline file may be such a summary JSON).
 */
const BENCH_SPREAD_LIMIT_PERCENT = 5.0;

/**
 * Median run by RPS, spread, rerun trigger and noisy flag for one group of runs.
 *
 * Discarded runs are ignored. Only the latest set counts. A spread above the limit
 * on the first set asks for one rerun; above the limit on the rerun marks it noisy.
 *
 * @param  list<array<string, mixed>>  $runs
 * @return array{server: string, scenario: string, label: string, setting: string, runs: int, reported_runs: list<array<string, mixed>>, median: array<string, mixed>, spread_percent: float, rerun_needed: bool, noisy: bool}
 */
function benchSummarize(array $runs): array
{
    $runs = array_values(array_filter($runs, fn (array $run): bool => ! ($run['discarded'] ?? false)));

    if ($runs === []) {
        throw new InvalidArgumentException('No result runs to summarize.');
    }

    $groups = array_unique(array_map(fn (array $run): string => ($run['server'] ?? '').'|'.($run['scenario'] ?? '').'|'.($run['label'] ?? ''), $runs));

    if (count($groups) > 1) {
        throw new InvalidArgumentException('Runs from different server/scenario/label groups cannot share one median: '.implode(', ', $groups));
    }

    $latestSet = max(array_map(fn (array $run): int => (int) ($run['set'] ?? 1), $runs));
    $runs = array_values(array_filter($runs, fn (array $run): bool => (int) ($run['set'] ?? 1) === $latestSet));
    $reported = $runs;

    usort($runs, fn (array $a, array $b): int => $a['rps'] <=> $b['rps']);

    $median = $runs[intdiv(count($runs), 2)];
    $spread = $median['rps'] == 0 ? 0.0 : ($runs[count($runs) - 1]['rps'] - $runs[0]['rps']) / $median['rps'] * 100;
    $overLimit = count($runs) > 1 && $spread > BENCH_SPREAD_LIMIT_PERCENT;

    return [
        'server' => (string) $median['server'],
        'scenario' => (string) $median['scenario'],
        'label' => (string) ($median['label'] ?? ''),
        'setting' => (string) ($median['concurrency'] ?? 'default'),
        'runs' => count($runs),
        'reported_runs' => $reported,
        'median' => $median,
        'spread_percent' => (float) $spread,
        'rerun_needed' => $overLimit && $latestSet === 1,
        'noisy' => $overLimit && $latestSet > 1,
    ];
}

/**
 * @param  list<string>  $paths
 * @return array{server: string, scenario: string, label: string, setting: string, runs: int, reported_runs: list<array<string, mixed>>, median: array<string, mixed>, spread_percent: float, rerun_needed: bool, noisy: bool}
 */
function benchSummarizeFiles(array $paths): array
{
    return benchSummarize(benchReadRuns($paths));
}

/**
 * @param  list<string>  $paths
 * @return list<array<string, mixed>>
 */
function benchReadRuns(array $paths): array
{
    $runs = [];

    foreach ($paths as $path) {
        $files = is_dir($path) ? (glob(rtrim($path, '/').'/*.json') ?: []) : [$path];

        foreach ($files as $file) {
            $runs[] = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        }
    }

    return $runs;
}

/**
 * RPS ratio against the nginx-fpm baseline; `~` when the baseline is noisy.
 *
 * @param  array{median: array<string, mixed>, noisy: bool}  $summary
 * @param  array{median: array<string, mixed>, noisy: bool}|null  $baseline
 */
function benchRatio(array $summary, ?array $baseline): string
{
    if ($baseline === null || $baseline['median']['rps'] == 0) {
        return 'n/a';
    }

    return ($baseline['noisy'] ? '~' : '').number_format($summary['median']['rps'] / $baseline['median']['rps'], 2).'x';
}

/**
 * The committed summary document for one server, scenario and setting.
 *
 * @param  array<string, mixed>  $summary
 * @param  array<string, mixed>|null  $baseline
 * @return array<string, mixed>
 */
function benchSummaryDocument(array $summary, ?array $baseline): array
{
    $median = $summary['median'];
    $perRun = fn (array $run): array => [
        'set' => (int) ($run['set'] ?? 1),
        'repeat' => (int) ($run['repeat'] ?? 1),
        'rps' => $run['rps'],
        'p50_ms' => $run['p50_ms'],
        'p95_ms' => $run['p95_ms'],
        'p99_ms' => $run['p99_ms'],
        'error_percent' => $run['error_percent'],
        'idle_memory_mib' => $run['idle_memory_mib'] ?? [],
        'peak_memory_mib' => $run['peak_memory_mib'] ?? [],
        'avg_cpu_percent' => $run['avg_cpu_percent'] ?? [],
    ];

    return [
        'server' => $summary['server'],
        'scenario' => $summary['scenario'],
        'label' => $summary['label'],
        'setting' => $summary['setting'],
        'commit' => $median['commit'] ?? 'unknown',
        'image' => $median['image'] ?? 'unknown',
        'image_digest' => $median['image_digest'] ?? 'unknown',
        'date' => $median['date'] ?? null,
        'runs' => array_map($perRun, $summary['reported_runs']),
        'median' => $perRun($median),
        'spread_percent' => $summary['spread_percent'],
        'noisy' => $summary['noisy'],
        'baseline_server' => $baseline['server'] ?? null,
        'ratio' => benchRatio($summary, $baseline),
    ];
}

/**
 * Loads a baseline from result runs, or from a summary JSON written by --write.
 *
 * @param  list<string>  $paths
 * @return array<string, mixed>
 */
function benchLoadBaseline(array $paths): array
{
    if (count($paths) === 1 && is_file($paths[0])) {
        $decoded = json_decode((string) file_get_contents($paths[0]), true, flags: JSON_THROW_ON_ERROR);

        if (isset($decoded['median'], $decoded['runs']) && is_array($decoded['runs'])) {
            return [
                'server' => $decoded['server'],
                'median' => $decoded['median'],
                'noisy' => (bool) $decoded['noisy'],
            ];
        }
    }

    return benchSummarizeFiles($paths);
}

/**
 * The container that serves requests for a server; OpenLiteSpeed runs in its own container.
 */
function benchServingContainer(string $server): string
{
    return $server === 'openlitespeed' ? 'openlitespeed' : 'web';
}

/**
 * Idle and peak memory (MiB) of every container, with the serving container named.
 *
 * @param  array{server: string, median: array<string, mixed>}  $summary
 * @return array{serving: string, idle: array<string, float|int>, peak: array<string, float|int>}
 */
function benchMemory(array $summary): array
{
    return [
        'serving' => benchServingContainer($summary['server']),
        'idle' => $summary['median']['idle_memory_mib'] ?? [],
        'peak' => $summary['median']['peak_memory_mib'] ?? [],
    ];
}

/**
 * One BENCHMARKS.md table row.
 *
 * @param  array{server: string, setting: string, median: array<string, mixed>, spread_percent: float, noisy: bool}  $summary
 * @param  array{median: array<string, mixed>, noisy: bool}|null  $baseline
 */
function benchRow(array $summary, ?array $baseline): string
{
    $median = $summary['median'];
    $serving = benchServingContainer($summary['server']);
    $spread = number_format($summary['spread_percent'], 1).'%'.($summary['noisy'] ? ' noisy' : '');

    return '| '.implode(' | ', [
        $summary['server'],
        $summary['setting'],
        number_format($median['rps'], 0, '.', ''),
        number_format($median['p50_ms'], 1, '.', ''),
        number_format($median['p95_ms'], 1, '.', ''),
        number_format($median['p99_ms'], 1, '.', ''),
        number_format($median['error_percent'], 2, '.', ''),
        number_format($median['peak_memory_mib'][$serving] ?? 0, 0, '.', ''),
        number_format($median['avg_cpu_percent'][$serving] ?? 0, 0, '.', ''),
        $spread,
        benchRatio($summary, $baseline),
        '',
    ]).' |';
}

/**
 * The worker memory table from the memory-<server>.json documents: memory_bytes at requests 0, 1000, 5000
 * and 10000 (`—` when a stretch was not sampled), restarts, the highest memory_bytes and the growth slope.
 *
 * @param  list<string>  $servers
 * @param  array<string, array<string, mixed>>  $documents
 */
function benchMemoryTable(array $servers, array $documents): string
{
    $rows = '';

    foreach ($servers as $server) {
        foreach ($documents["memory-{$server}"]['cases'] ?? [] as $case) {
            $bytes = [];

            foreach ($case['samples'] as $sample) {
                $bytes[(int) $sample['requests']] = (int) $sample['memory_bytes'];
            }

            $rows .= '| '.implode(' | ', [
                $server,
                $case['case'],
                $case['mode'],
                $case['max_requests'] ?? '—',
                ...array_map(fn (int $requests): string => (string) ($bytes[$requests] ?? '—'), [0, 1000, 5000, 10000]),
                $case['restarts'],
                $bytes === [] ? '—' : max($bytes),
                number_format((float) $case['growth_kib_per_1000'], 2, '.', ''),
            ])." |\n";
        }
    }

    if ($rows === '') {
        return '';
    }

    return "\n### Worker memory growth (memory_bytes, 1 worker, sequential requests)\n\n"
        ."| Server | Case | Mode | Max-requests | Bytes at 0 | at 1000 | at 5000 | at 10000 | Restarts | Highest bytes | KiB per 1000 requests |\n"
        ."|---|---|---|---|---|---|---|---|---|---|---|\n".$rows;
}

/**
 * All BENCHMARKS.md result tables (ping and search with fair then tuned rows, memory, timeouts) built
 * from the committed summary JSON files in one results directory.
 */
function benchTables(string $directory): string
{
    $documents = [];
    $present = [];

    foreach (glob(rtrim($directory, '/').'/*.json') ?: [] as $file) {
        $document = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $documents[basename($file, '.json')] = $document;

        if (preg_match('/^(.+)-(?:ping|search)-(?:fair|tuned)$/', basename($file, '.json'), $matches) === 1) {
            $present[$matches[1]] = true;
        }
    }

    $known = ['nginx-fpm', 'apache-fpm', 'openlitespeed', 'frankenphp', 'frankenphp-octane', 'frankenphp-worker'];
    $servers = array_values(array_filter($known, fn (string $server): bool => isset($present[$server])));
    // The baseline each summary was compared with is recorded in it; fall back to the old rule.
    $baseline = null;
    foreach ($documents as $name => $document) {
        if (preg_match('/-(?:ping|search)-(?:fair|tuned)$/', (string) $name) === 1 && ($document['baseline_server'] ?? null) !== null) {
            $baseline = (string) $document['baseline_server'];
            break;
        }
    }
    $baseline ??= in_array('nginx-fpm', $servers, true) ? 'nginx-fpm' : 'frankenphp';

    $header = "| Server | Setting | RPS | p50 ms | p95 ms | p99 ms | Errors % | Peak MiB (serving) | CPU % (serving) | Spread | RPS vs {$baseline} | Notes |\n"
        ."|---|---|---|---|---|---|---|---|---|---|---|---|\n";
    $out = '';

    foreach (['ping' => '/api/ping (bootstrap-dominated)', 'search' => '/listings?make=BMW (DB-dominated)'] as $scenario => $title) {
        $out .= "### {$title}\n\n".$header;

        foreach (['fair', 'tuned'] as $label) {
            foreach ($servers as $server) {
                $document = $documents["{$server}-{$scenario}-{$label}"] ?? null;

                if ($document === null) {
                    continue;
                }

                $median = $document['median'];
                $serving = benchServingContainer($server);
                $out .= '| '.implode(' | ', [
                    $server,
                    "{$label}: {$document['setting']}",
                    number_format($median['rps'], 0, '.', ''),
                    number_format($median['p50_ms'], 1, '.', ''),
                    number_format($median['p95_ms'], 1, '.', ''),
                    number_format($median['p99_ms'], 1, '.', ''),
                    number_format($median['error_percent'], 2, '.', ''),
                    number_format($median['peak_memory_mib'][$serving] ?? 0, 0, '.', ''),
                    number_format($median['avg_cpu_percent'][$serving] ?? 0, 0, '.', ''),
                    number_format($document['spread_percent'], 1).'%'.($document['noisy'] ? ' noisy' : ''),
                    $document['ratio'],
                    '',
                ])." |\n";
            }
        }

        $out .= "\n";
    }

    $out .= "### Memory and CPU at 50 concurrent users (fair ping run)\n\n"
        ."| Server | Serving container | Idle MiB | Peak MiB | Avg CPU % | db idle MiB | db peak MiB | db avg CPU % | Idle `web` MiB (OLS only) |\n"
        ."|---|---|---|---|---|---|---|---|---|\n";

    foreach ($servers as $server) {
        $document = $documents["{$server}-ping-fair"] ?? null;

        if ($document === null) {
            continue;
        }

        $memory = benchMemory(['server' => $server, 'median' => $document['median']]);
        $cpu = $document['median']['avg_cpu_percent'] ?? [];
        $serving = $memory['serving'];
        $out .= '| '.implode(' | ', [
            $server,
            $serving,
            number_format($memory['idle'][$serving] ?? 0, 0, '.', ''),
            number_format($memory['peak'][$serving] ?? 0, 0, '.', ''),
            number_format($cpu[$serving] ?? 0, 0, '.', ''),
            number_format($memory['idle']['db'] ?? 0, 0, '.', ''),
            number_format($memory['peak']['db'] ?? 0, 0, '.', ''),
            number_format($cpu['db'] ?? 0, 0, '.', ''),
            $serving === 'web' ? '—' : number_format($memory['peak']['web'] ?? 0, 0, '.', ''),
        ])." |\n";
    }

    $out .= benchMemoryTable($known, $documents);

    $timeoutRows = '';

    foreach ($servers as $server) {
        $document = $documents["timeouts-{$server}"] ?? null;

        foreach ($document['runs'] ?? [] as $run) {
            if ((int) $run['request'] !== 1) {
                continue;
            }

            $timeoutRows .= "| {$server} | {$run['label']} | {$run['status']} | ".number_format((float) $run['seconds'], 1)." | {$run['ending_layer']} |\n";
        }
    }

    // Only folders with timeout results get the table (the SL-08 runs skip the timeout step).
    if ($timeoutRows !== '') {
        $out .= "\n### Timeouts: GET /debug/sleep/30\n\n"
            ."| Server | Timeouts | Status | Seconds | Ended by |\n|---|---|---|---|---|\n".$timeoutRows;
    }

    return $out;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--tables=')) {
            echo benchTables(substr($argument, strlen('--tables=')));
            exit(0);
        }
    }

    $paths = [];
    $baselinePaths = [];
    $writePath = null;
    $selfBaseline = false;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--self-baseline') {
            $selfBaseline = true;
        } elseif (str_starts_with($argument, '--baseline=')) {
            $baselinePaths[] = substr($argument, strlen('--baseline='));
        } elseif (str_starts_with($argument, '--write=')) {
            $writePath = substr($argument, strlen('--write='));
        } else {
            $paths[] = $argument;
        }
    }

    if ($paths === []) {
        fwrite(STDERR, "Usage: php bench/summary.php <result-dir-or-file>... [--baseline=<dir-or-file>...] [--self-baseline] [--write=<file>]\n");
        exit(2);
    }

    $summary = benchSummarizeFiles($paths);
    $baseline = $baselinePaths === [] ? null : benchLoadBaseline($baselinePaths);
    $baseline ??= $summary['server'] === 'nginx-fpm' || $selfBaseline ? $summary : null;

    echo benchRow($summary, $baseline), "\n";

    if ($writePath !== null) {
        if (! is_dir(dirname($writePath))) {
            mkdir(dirname($writePath), 0777, true);
        }
        file_put_contents($writePath, json_encode(benchSummaryDocument($summary, $baseline), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    if ($summary['rerun_needed']) {
        fwrite(STDERR, "Spread above 5%: rerun the scenario once.\n");
        exit(3);
    }
}
