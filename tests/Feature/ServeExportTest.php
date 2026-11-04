<?php

declare(strict_types=1);

use App\Ai\AbortWatcher;
use App\Contracts\ShutdownScheduler;
use App\Models\Export;
use App\Support\ExportStreamer;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/serve-export-'.bin2hex(random_bytes(4));
    $this->logPath = $this->directory.'/exports.jsonl';
    File::ensureDirectoryExists($this->directory);
    config([
        'app.debug_routes' => true,
        'exports.directory' => $this->directory,
        'exports.log' => $this->logPath,
        'exports.offload_header' => null,
        'exports.offload_enabled' => ['x-accel', 'x-sendfile', 'x-litespeed'],
    ]);

    $this->deferred = [];
    $deferred = &$this->deferred;
    $this->app->instance(ShutdownScheduler::class, new class($deferred) implements ShutdownScheduler
    {
        public function __construct(private array &$callbacks) {}

        public function defer(Closure $callback): void
        {
            $this->callbacks[] = $callback;
        }
    });
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function serveExportFile(string $id, int $bytes): string
{
    $path = config('exports.directory')."/{$id}.csv";
    $content = str_repeat("1,2,three\n", intdiv($bytes, 10)).str_repeat('x', $bytes % 10);
    file_put_contents($path, $content);
    Export::query()->create([
        'id' => $id,
        'path' => $path,
        'bytes' => $bytes,
        'sha256' => hash('sha256', $content),
        'rows' => 1,
        'generated_at' => now(),
    ]);

    return $content;
}

/**
 * Runs the shutdown callbacks the request registered, as the end of the PHP request would, and returns the log lines.
 *
 * @return list<array<string, mixed>>
 */
function serveExportLog(): array
{
    foreach (test()->deferred as $callback) {
        $callback();
    }

    test()->deferred = [];

    if (! is_file(test()->logPath)) {
        return [];
    }

    return array_map(
        fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", (string) file_get_contents(test()->logPath)))),
    );
}

test('AC-5 the route is a JSON 404 while debug routes are off', function () {
    serveExportFile('listings', 100);
    config(['app.debug_routes' => false]);

    $this->getJson('/exports/listings')->assertNotFound()->assertJsonPath('success', false);
});

test('AC-5 an unknown export id is a JSON 404', function () {
    $this->getJson('/exports/nope')->assertNotFound()->assertJsonPath('success', false);
});

test('AC-5 an id that breaks the route constraint is a JSON 404', function () {
    $this->getJson('/exports/Not_Valid')->assertNotFound();
    $this->getJson('/exports/'.str_repeat('a', 41))->assertNotFound();
});

test('AC-5 a record whose file is missing is a JSON 404 saying it was not generated', function () {
    serveExportFile('listings', 100);
    unlink($this->directory.'/listings.csv');

    $this->getJson('/exports/listings')->assertNotFound()->assertJsonPath('message', 'Export not generated.');
});

test('AC-5 a mode other than offload or stream is a 422 with a field error', function () {
    serveExportFile('listings', 100);

    $this->getJson('/exports/listings?mode=copy')
        ->assertStatus(422)
        ->assertJsonValidationErrors('mode', 'errors');
});

test('AC-5 the route answers 429 over its throttle', function () {
    serveExportFile('listings', 100);
    config(['exports.per_minute' => 2]);

    $this->getJson('/exports/listings')->assertOk();
    $this->getJson('/exports/listings')->assertOk();
    $this->getJson('/exports/listings')->assertStatus(429)->assertJsonPath('success', false);
});

test('AC-2 each forced header answers with an empty body, the right value and the file headers', function (string $forced, string $header, string $value) {
    $content = serveExportFile('listings', 2500);
    config(['exports.offload_header' => $forced]);

    $response = $this->get('/exports/listings?mode=offload');

    $response->assertOk()
        ->assertHeader('X-Export-Mode', 'offload')
        ->assertHeader($header, str_replace('{dir}', $this->directory, $value))
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="listings.csv"')
        ->assertHeader('Content-Length', (string) strlen($content));
    expect($response->getContent())->toBe('');
})->with([
    'nginx' => ['x-accel', 'X-Accel-Redirect', '/_protected_exports/listings.csv'],
    'apache and caddy' => ['x-sendfile', 'X-Sendfile', '{dir}/listings.csv'],
    'litespeed' => ['x-litespeed', 'X-LiteSpeed-Location', '/_protected_exports/listings.csv'],
]);

test('AC-2 offload is the default mode', function () {
    serveExportFile('listings', 100);
    config(['exports.offload_header' => 'x-accel']);

    $this->get('/exports/listings')->assertHeader('X-Export-Mode', 'offload');
});

test('AC-2 the header follows the server software of the request', function (string $software, bool $intercept, ?string $header) {
    serveExportFile('listings', 100);

    $response = $this->withServerVariables(['SERVER_SOFTWARE' => $software, 'EXPORTS_SENDFILE_INTERCEPT' => $intercept ? '1' : ''])->get('/exports/listings');

    if ($header === null) {
        $response->assertHeader('X-Export-Mode', 'stream-fallback');
        expect($response->headers->has('X-Accel-Redirect') || $response->headers->has('X-Sendfile') || $response->headers->has('X-LiteSpeed-Location'))->toBeFalse();
    } else {
        $response->assertHeader('X-Export-Mode', 'offload');
        expect($response->headers->has($header))->toBeTrue();
    }
})->with([
    ['nginx/1.27.0', false, 'X-Accel-Redirect'],
    ['Apache/2.4.62 (Debian)', false, 'X-Sendfile'],
    ['Caddy', true, 'X-Sendfile'],
    ['FrankenPHP', true, 'X-Sendfile'],
    ['LiteSpeed', false, 'X-LiteSpeed-Location'],
    ['something-else', false, null],
    ['', false, null],
]);

test('AC-2 FrankenPHP without the intercept mark (worker mode, Octane) never sends X-Sendfile', function (string $software) {
    $content = serveExportFile('listings', 70_000);

    $response = $this->withServerVariables(['SERVER_SOFTWARE' => $software])->get('/exports/listings?mode=offload');

    $response->assertOk()->assertHeader('X-Export-Mode', 'stream-fallback');
    expect($response->headers->has('X-Sendfile'))->toBeFalse()
        ->and($response->streamedContent())->toBe($content);
})->with(['FrankenPHP', 'Caddy', 'FrankenPHP/1.9 Caddy/2']);

test('AC-2 the Caddyfile that intercepts X-Sendfile is the only one that marks itself for it', function () {
    $root = dirname(__DIR__, 2).'/.ddev/servers';

    expect(file_get_contents($root.'/frankenphp/web-build/Caddyfile.frankenphp'))->toMatch('/env\s+EXPORTS_SENDFILE_INTERCEPT\s+1/')
        ->and(file_get_contents($root.'/frankenphp-worker/web-build/Caddyfile.frankenphp'))->not->toContain('EXPORTS_SENDFILE_INTERCEPT');
});

test('AC-2 a switched-off header falls back to streaming the same bytes and says so', function () {
    $content = serveExportFile('listings', 70_000);
    config(['exports.offload_header' => 'x-litespeed', 'exports.offload_enabled' => ['x-accel']]);

    $response = $this->get('/exports/listings?mode=offload');

    $response->assertOk()->assertHeader('X-Export-Mode', 'stream-fallback');
    expect($response->streamedContent())->toBe($content);
});

test('AC-2 an offload download is logged with zero bytes written by PHP', function () {
    serveExportFile('listings', 100);
    config(['exports.offload_header' => 'x-accel']);

    $this->withServerVariables(['SERVER_SOFTWARE' => 'nginx/1.27'])->withHeaders(['X-Bench-Run' => 'nginx-fpm-offload-1-42'])->get('/exports/listings');
    $lines = serveExportLog();

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['bytes_sent_by_php'])->toBe(0)
        ->and($lines[0]['aborted'])->toBeFalse()
        ->and($lines[0]['mode'])->toBe('offload')
        ->and($lines[0]['server'])->toBe('nginx/1.27')
        ->and($lines[0]['bench_run'])->toBe('nginx-fpm-offload-1-42');
});

test('AC-3 stream mode returns the identical bytes in 64 KiB chunks and logs every byte', function () {
    $content = serveExportFile('listings', ExportStreamer::CHUNK_BYTES * 3 + 123);

    $response = $this->get('/exports/listings?mode=stream');
    $body = $response->streamedContent();

    $response->assertOk()
        ->assertHeader('X-Export-Mode', 'stream')
        ->assertHeader('Content-Length', (string) strlen($content))
        ->assertHeader('Content-Disposition', 'attachment; filename="listings.csv"');
    expect(hash('sha256', $body))->toBe(hash('sha256', $content));

    $lines = serveExportLog();
    expect($lines[0]['mode'])->toBe('stream')
        ->and($lines[0]['bytes_sent_by_php'])->toBe(strlen($content))
        ->and($lines[0]['aborted'])->toBeFalse();
});

test('AC-3 streaming a file never loads it into memory', function () {
    serveExportFile('listings', 5 * 1024 * 1024);
    config(['exports.offload_header' => 'x-accel']);
    $this->get('/exports/listings?mode=offload');
    $warmUp = $this->get('/exports/listings?mode=stream');
    ob_start(static fn (): string => '', 65536);
    $warmUp->baseResponse->sendContent();
    ob_end_clean();

    memory_reset_peak_usage();
    $this->get('/exports/listings?mode=offload');
    $offloadPeak = memory_get_peak_usage();

    memory_reset_peak_usage();
    $response = $this->get('/exports/listings?mode=stream');
    ob_start(static fn (): string => '', 65536);
    $response->baseResponse->sendContent();
    ob_end_clean();
    $streamPeak = memory_get_peak_usage();

    expect($streamPeak - $offloadPeak)->toBeLessThan(2 * 1024 * 1024);
});

test('AC-3 stream mode flushes the output buffer after every 64 KiB chunk', function () {
    $chunks = 3;
    serveExportFile('listings', ExportStreamer::CHUNK_BYTES * $chunks + 100);
    $response = $this->get('/exports/listings?mode=stream');
    $flushes = [];

    ob_start(static function (string $buffer, int $phase) use (&$flushes): string {
        if (($phase & PHP_OUTPUT_HANDLER_FLUSH) !== 0) {
            $flushes[] = strlen($buffer);
        }

        return '';
    });
    $response->baseResponse->sendContent();
    ob_end_clean();

    expect($flushes)->toHaveCount($chunks + 1)
        ->and(array_slice($flushes, 0, $chunks))->each->toBe(ExportStreamer::CHUNK_BYTES)
        ->and($flushes[$chunks])->toBe(100);
});

test('AC-3 a HEAD request in stream mode sends the headers only and logs zero bytes', function () {
    $content = serveExportFile('listings', ExportStreamer::CHUNK_BYTES * 2);
    $this->app->instance(AbortWatcher::class, new class implements AbortWatcher
    {
        public int $calls = 0;

        public function aborted(): bool
        {
            $this->calls++;

            return false;
        }
    });

    $response = $this->call('HEAD', '/exports/listings?mode=stream');
    $lines = serveExportLog();

    $response->assertOk()->assertHeader('X-Export-Mode', 'stream')->assertHeader('Content-Length', (string) strlen($content));
    expect($response->getContent())->toBe('')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['bytes_sent_by_php'])->toBe(0)
        ->and($lines[0]['aborted'])->toBeFalse()
        ->and($this->app->make(AbortWatcher::class)->calls)->toBe(0);
});

test('AC-4 each download logs one JSON line with the measured fields', function () {
    serveExportFile('listings', 1000);

    $this->get('/exports/listings?mode=stream')->streamedContent();
    $lines = serveExportLog();

    expect($lines)->toHaveCount(1)
        ->and(array_keys($lines[0]))->toBe(['invocation_id', 'export_id', 'server', 'mode', 'bench_run', 'php_held_ms', 'peak_memory_bytes', 'cpu_ms', 'cpu_scope', 'bytes_sent_by_php', 'aborted', 'abort_reason'])
        ->and($lines[0]['invocation_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($lines[0]['export_id'])->toBe('listings')
        ->and($lines[0]['php_held_ms'])->toBeGreaterThanOrEqual(0)
        ->and($lines[0]['peak_memory_bytes'])->toBeGreaterThan(0)
        ->and($lines[0]['cpu_ms'])->toBeNumeric()
        ->and($lines[0]['cpu_scope'])->toBe('request')
        ->and($lines[0]['abort_reason'])->toBeNull()
        ->and($lines[0]['bench_run'])->toBeNull();
});

test('AC-4 a client that disconnects stops the read and is logged as aborted', function () {
    $size = ExportStreamer::CHUNK_BYTES * 10;
    serveExportFile('listings', $size);
    $this->app->instance(AbortWatcher::class, new class implements AbortWatcher
    {
        private int $seen = 0;

        public function aborted(): bool
        {
            return ++$this->seen >= 3;
        }
    });

    $response = $this->get('/exports/listings?mode=stream');
    $body = $response->streamedContent();
    $lines = serveExportLog();

    $response->assertHeader('Content-Length', (string) $size);
    expect(strlen($body))->toBe(ExportStreamer::CHUNK_BYTES * 3)
        ->and($lines[0]['aborted'])->toBeTrue()
        ->and($lines[0]['abort_reason'])->toBe('client_disconnect')
        ->and($lines[0]['bytes_sent_by_php'])->toBe(ExportStreamer::CHUNK_BYTES * 3);
});

test('AC-4 a stream that outlasts exports.stream_max_seconds stops and is logged as aborted for max_duration', function () {
    $size = ExportStreamer::CHUNK_BYTES * 10;
    serveExportFile('listings', $size);
    config(['exports.stream_max_seconds' => 0.05]);
    $this->app->instance(AbortWatcher::class, new class implements AbortWatcher
    {
        public function aborted(): bool
        {
            usleep(30_000);

            return false;
        }
    });

    $body = $this->get('/exports/listings?mode=stream')->streamedContent();
    $lines = serveExportLog();

    expect(strlen($body))->toBeLessThan($size)->toBeGreaterThanOrEqual(ExportStreamer::CHUNK_BYTES)
        ->and($lines[0]['aborted'])->toBeTrue()
        ->and($lines[0]['abort_reason'])->toBe('max_duration')
        ->and($lines[0]['bytes_sent_by_php'])->toBe(strlen($body));
});

test('AC-4 the default longest stream is 300 seconds', function () {
    expect(config('exports.stream_max_seconds'))->toBe(300.0);
});

test('AC-4 cpu_ms covers one request where PHP serves one at a time and is null where threads share the process', function (string $software, bool $shared) {
    serveExportFile('listings', 1000);

    $this->withServerVariables(['SERVER_SOFTWARE' => $software])->get('/exports/listings?mode=stream')->streamedContent();
    $line = serveExportLog()[0];

    if ($shared) {
        expect($line['cpu_ms'])->toBeNull()->and($line['cpu_scope'])->toBe('process');
    } else {
        expect($line['cpu_ms'])->toBeNumeric()->toBeGreaterThanOrEqual(0)->and($line['cpu_scope'])->toBe('request');
    }
})->with([
    ['nginx/1.27', false],
    ['Apache/2.4', false],
    ['LiteSpeed', false],
    ['FrankenPHP', true],
    ['Caddy', true],
    ['FrankenPHP/1.9 Caddy/2.9', true],
]);

test('AC-4 the CPU baseline is read in public/index.php before the framework loads', function () {
    $index = file_get_contents(dirname(__DIR__, 2).'/public/index.php');

    expect(strpos($index, "define('LARAVEL_START_RUSAGE', getrusage()"))->toBeInt()
        ->toBeLessThan(strpos($index, "require __DIR__.'/../vendor/autoload.php'"))
        ->toBeGreaterThan(strpos($index, "define('LARAVEL_START'"));
});

test('AC-4 X-Bench-Run keeps only [A-Za-z0-9._-] up to 80 characters, else the log has null', function (string $sent, ?string $logged) {
    serveExportFile('listings', 100);
    config(['exports.offload_header' => 'x-accel']);

    $this->withHeaders(['X-Bench-Run' => $sent])->get('/exports/listings');

    expect(serveExportLog()[0]['bench_run'])->toBe($logged);
})->with([
    'plain' => ['export-load-nginx-fpm-offload-1.2_x', 'export-load-nginx-fpm-offload-1.2_x'],
    'too long' => [str_repeat('a', 100), str_repeat('a', 80)],
    'space' => ['two words', null],
    'quote' => ['a"b', null],
    'slash' => ['../x', null],
]);

test('AC-4 invalid UTF-8 in the server string still logs a line, with the bad bytes substituted', function () {
    serveExportFile('listings', 100);
    config(['exports.offload_header' => 'x-accel']);

    $this->withServerVariables(['SERVER_SOFTWARE' => "nginx/\xB1\x31"])->get('/exports/listings');
    $lines = serveExportLog();

    expect($lines)->toHaveCount(1)->and($lines[0]['server'])->toStartWith('nginx/')->and($lines[0]['server'])->toEndWith('1');
});

test('AC-4 a log line that cannot be written is reported and never thrown from the shutdown callback', function () {
    Exceptions::fake();
    serveExportFile('listings', 100);
    config(['exports.offload_header' => 'x-accel', 'exports.log' => $this->directory]);

    $this->get('/exports/listings');
    foreach ($this->deferred as $callback) {
        $callback();
    }

    Exceptions::assertReportedCount(1);
});
