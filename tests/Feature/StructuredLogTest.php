<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Logging\FrankenphpLogHandler;
use App\Logging\RequestContextProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Logger;

final class LogProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Log::channel('frankenphp')->info('from job');
    }
}

final class NestingLogProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        LogProbeJob::dispatchSync();
        Log::channel('frankenphp')->info('after nested job');
    }
}

final class FailingLogProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('job failed');
    }
}

/**
 * @param  array<int, array{0: string, 1: int, 2: array<string, mixed>}>  $calls
 */
function useFrankenphpChannel(array &$calls, bool $withWriter = true, $stderr = null): void
{
    config([
        'logging.channels.frankenphp.handler_with' => array_filter([
            'writer' => $withWriter ? function (string $message, int $level, array $context) use (&$calls): void {
                $calls[] = [$message, $level, $context];
            } : null,
            'stderr' => $stderr,
        ]),
        'ai.server.mode' => 'frankenphp-worker',
    ]);
    Log::forgetChannel('frankenphp');
}

/**
 * @return resource
 */
function memoryStream()
{
    return fopen('php://memory', 'w+');
}

function streamLines($stream): array
{
    rewind($stream);

    return array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
}

beforeEach(function (): void {
    $ignored = [];
    useFrankenphpChannel($ignored);

    Route::get('/_log-probe', function () {
        Log::channel('frankenphp')->warning('probe', ['password' => 'hunter2', 'nested' => ['Api_Key' => 'k', 'ok' => 1]]);

        return response('ok', 200, ['Content-Type' => 'text/plain']);
    })->name('log.probe');
});

it('AC-1 writes through frankenphp_log with the request fields', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);

    $this->get('/_log-probe?secret=1', ['X-Request-Id' => 'abc-12345678'])->assertOk();

    expect($calls)->toHaveCount(1);
    [$message, $level, $context] = $calls[0];
    expect($message)->toBe('probe')
        ->and($level)->toBe(4)
        ->and($context['level_name'])->toBe('WARNING')
        ->and($context['request'])->toMatchArray([
            'request_id' => 'abc-12345678',
            'method' => 'GET',
            'path' => '/_log-probe',
            'route' => 'log.probe',
            'server_mode' => 'frankenphp-worker',
            'context' => 'http',
        ])
        ->and($context['request']['worker'])->toBeBool()
        ->and($context['request']['elapsed_ms'])->toBeInt();
});

it('AC-2 falls back to one JSON line on stderr', function (): void {
    $stream = memoryStream();
    $calls = [];
    useFrankenphpChannel($calls, false, $stream);

    $this->get('/_log-probe')->assertOk();

    $lines = streamLines($stream);
    expect($lines)->toHaveCount(1);
    $entry = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    expect($entry)->toHaveKeys(['time', 'level', 'message', 'channel', 'context', 'request'])
        ->and($entry['level'])->toBe('warning')
        ->and($entry['message'])->toBe('probe')
        ->and($entry['request']['path'])->toBe('/_log-probe')
        ->and($entry['request']['request_id'])->not->toBeEmpty();
});

it('AC-2 the CLI entry has null request fields and cli context', function (): void {
    $stream = memoryStream();
    $calls = [];
    useFrankenphpChannel($calls, false, $stream);

    Log::channel('frankenphp')->info('from cli');

    $entry = json_decode(streamLines($stream)[0], true, flags: JSON_THROW_ON_ERROR);
    expect($entry['request']['context'])->toBe('cli')
        ->and($entry['request']['request_id'])->toBeNull()
        ->and($entry['request']['method'])->toBeNull();
});

it('AC-3 reuses a valid X-Request-Id and replaces an invalid one', function (): void {
    $this->get('/_log-probe', ['X-Request-Id' => 'valid-id-1234'])->assertHeader('X-Request-Id', 'valid-id-1234');

    foreach (['short', 'bad id with spaces!', str_repeat('a', 65)] as $invalid) {
        $id = $this->get('/_log-probe', ['X-Request-Id' => $invalid])->headers->get('X-Request-Id');
        expect($id)->not->toBe($invalid)->and($id)->toMatch('/^[0-9A-Z]{26}$/');
    }

    expect($this->get('/_log-probe')->headers->get('X-Request-Id'))->toMatch('/^[0-9A-Z]{26}$/');
});

it('AC-3 the header is present on a 404', function (): void {
    expect($this->getJson('/api/nope-not-here')->headers->get('X-Request-Id'))->not->toBeNull();
});

it('AC-4 two requests log different request ids', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);

    $this->get('/_log-probe');
    $this->get('/_log-probe');

    expect($calls)->toHaveCount(2)
        ->and($calls[0][2]['request']['request_id'])->not->toBe($calls[1][2]['request']['request_id']);
});

it('AC-4 AC-1 a worker reset leaves nothing of the previous request in later entries', function (): void {
    $_SERVER['FRANKENPHP_WORKER'] = '1';
    $calls = [];
    useFrankenphpChannel($calls);

    Route::get('/_log-second', function () {
        Log::channel('frankenphp')->info('second');

        return response('ok');
    })->name('log.second');

    try {
        $this->get('/_log-probe', ['X-Request-Id' => 'first-request-1'])->assertOk();

        app()->forgetScopedInstances();
        Context::clearResolvedInstance();
        Log::channel('frankenphp')->info('between requests');

        $this->get('/_log-second', ['X-Request-Id' => 'second-request-2'])->assertOk();
    } finally {
        unset($_SERVER['FRANKENPHP_WORKER']);
    }

    expect($calls)->toHaveCount(3);
    [$first, $between, $second] = array_map(fn (array $call): array => $call[2]['request'], $calls);

    expect($first)->toMatchArray(['request_id' => 'first-request-1', 'path' => '/_log-probe', 'route' => 'log.probe', 'worker' => true])
        ->and($between)->toMatchArray([
            'request_id' => null,
            'method' => null,
            'path' => null,
            'route' => null,
            'context' => 'cli',
            'worker' => true,
        ])
        ->and($second)->toMatchArray(['request_id' => 'second-request-2', 'path' => '/_log-second', 'route' => 'log.second', 'context' => 'http', 'worker' => true])
        ->and(json_encode([$between, $second]))->not->toContain('first-request-1')->not->toContain('log.probe')->not->toContain('/_log-probe');
});

it('AC-5 NFR-1 redacts sensitive context keys recursively', function (): void {
    $stream = memoryStream();
    $calls = [];
    useFrankenphpChannel($calls, false, $stream);

    $this->get('/_log-probe')->assertOk();

    $raw = implode("\n", streamLines($stream));
    $entry = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    expect($entry['context']['password'])->toBe('[redacted]')
        ->and($entry['context']['nested']['Api_Key'])->toBe('[redacted]')
        ->and($entry['context']['nested']['ok'])->toBe(1)
        ->and($raw)->not->toContain('hunter2');
});

it('AC-5 redacts token, cookie and authorization keys on the function path', function (): void {
    $calls = [];
    $handler = new FrankenphpLogHandler(function (string $m, int $l, array $c) use (&$calls): void {
        $calls[] = $c;
    });
    $logger = new Logger('t', [$handler], [new RequestContextProcessor]);

    $logger->error('x', ['Authorization' => 'Bearer z', 'session_cookie' => 'c', 'access_token' => 't']);

    expect($calls[0]['context'])->toBe(['Authorization' => '[redacted]', 'session_cookie' => '[redacted]', 'access_token' => '[redacted]']);
});

it('F4 F17 the formatter keeps an exception message and class and normalizes objects', function (): void {
    $calls = [];
    $handler = new FrankenphpLogHandler(function (string $m, int $l, array $c) use (&$calls): void {
        $calls[] = $c;
    });
    $logger = new Logger('t', [$handler], [new RequestContextProcessor]);

    $object = new stdClass;
    $object->password = 'hunter2';
    $object->ok = 1;
    $logger->error('x', [
        'exception' => new RuntimeException('boom', 3, new LogicException('cause')),
        'object' => $object,
        'when' => new DateTimeImmutable('2026-11-17T10:00:00Z'),
    ]);

    $context = $calls[0]['context'];
    expect($context['exception'])->toMatchArray(['class' => RuntimeException::class, 'message' => 'boom', 'code' => 3])
        ->and($context['exception']['file'])->toMatch('/StructuredLogTest\.php:\d+$/')
        ->and($context['exception']['previous'])->toMatchArray(['class' => LogicException::class, 'message' => 'cause'])
        ->and($context['object'])->toBe(['stdClass' => ['password' => '[redacted]', 'ok' => 1]])
        ->and($context['when'])->toBeString()->toStartWith('2026-11-17T10:00:00')
        ->and(json_encode($calls))->not->toContain('hunter2');
});

it('F4 F17 the stderr line is the formatter output and carries the exception', function (): void {
    $stream = memoryStream();
    $logger = new Logger('t', [new FrankenphpLogHandler(null, $stream)], [new RequestContextProcessor]);

    $logger->error('failed', ['exception' => new RuntimeException('boom')]);

    $lines = streamLines($stream);
    expect($lines)->toHaveCount(1);
    $entry = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
    expect($entry['time'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/')
        ->and($entry['level'])->toBe('error')
        ->and($entry['channel'])->toBe('t')
        ->and($entry['context']['exception'])->toMatchArray(['class' => RuntimeException::class, 'message' => 'boom']);
});

it('F5 F12 AC-5 redacts by exact key and well-known suffix and leaves token counters alone', function (): void {
    $calls = [];
    $logger = new Logger('t', [new FrankenphpLogHandler(function (string $m, int $l, array $c) use (&$calls): void {
        $calls[] = $c;
    })], [new RequestContextProcessor]);

    $logger->info('x', [
        'X-Api-Key' => 'a', 'passwd' => 'b', 'Set-Cookie' => 'c', 'bearer' => 'd', 'private_key' => 'e',
        'access_key' => 'f', 'clientSecret' => 'g', 'refresh_token' => 'h', 'csrftoken' => 'i', 'headers' => ['Authorization' => 'j'],
        'tokens_in' => 10, 'tokens_out' => 20, 'tokens' => 30, 'token_count' => 40, 'prompt_tokens' => 50, 'max_tokens' => 60,
    ]);

    $context = $calls[0]['context'];
    foreach (['X-Api-Key', 'passwd', 'Set-Cookie', 'bearer', 'private_key', 'access_key', 'clientSecret', 'refresh_token', 'csrftoken'] as $key) {
        expect($context[$key])->toBe('[redacted]', $key);
    }
    expect($context['headers']['Authorization'])->toBe('[redacted]')
        ->and($context)->toMatchArray(['tokens_in' => 10, 'tokens_out' => 20, 'tokens' => 30, 'token_count' => 40, 'prompt_tokens' => 50, 'max_tokens' => 60]);
});

it('F7 an HTTP entry written before AssignRequestId runs has context http and no request id', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);
    $app = app();
    $previous = (fn () => $this->isRunningInConsole)->call($app);
    (fn () => $this->isRunningInConsole = false)->call($app);

    try {
        Log::channel('frankenphp')->info('before the middleware');
    } finally {
        (fn () => $this->isRunningInConsole = $previous)->call($app);
    }

    expect($calls[0][2]['request'])->toMatchArray(['context' => 'http', 'request_id' => null, 'method' => null, 'path' => null]);
});

it('F1 a failing queue job logs its exception with context queue', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);
    config(['queue.default' => 'database']);
    FailingLogProbeJob::dispatch()->onConnection('database');

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);

    Log::channel('frankenphp')->error('after failure');

    expect(end($calls)[2]['request']['context'])->toBe('queue');

    event(new Looping('database', 'default'));
    expect(Context::hasHidden(RequestContextProcessor::QUEUE_JOB_KEY))->toBeFalse();
});

it('F6 a sync job run inside a queued job leaves the outer job logging with context queue', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);
    NestingLogProbeJob::dispatch()->onConnection('database');

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    expect(array_column($calls, 0))->toBe(['from job', 'after nested job'])
        ->and(array_map(fn (array $call): string => $call[2]['request']['context'], $calls))->toBe(['queue', 'queue']);
});

it('F1 a JobProcessed listener still logs with context queue and the next loop clears the flag', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);
    Event::listen(JobProcessed::class, function (): void {
        Log::channel('frankenphp')->info('job processed');
    });
    LogProbeJob::dispatch()->onConnection('database');

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    expect(end($calls)[0])->toBe('job processed')
        ->and(end($calls)[2]['request']['context'])->toBe('queue');

    event(new WorkerStopping);
    expect(Context::hasHidden(RequestContextProcessor::QUEUE_JOB_KEY))->toBeFalse();
});

it('MNC-1 the default stack is single and has no frankenphp channel', function (): void {
    expect(config('logging.channels.stack.channels'))->toBe(['single'])
        ->and(config('logging.channels.single.driver'))->toBe('single');
});

it('MNC-1 the request id does not appear in the single log context', function (): void {
    $path = storage_path('logs/laravel.log');
    @unlink($path);

    $this->get('/_log-probe', ['X-Request-Id' => 'abc-12345678']);
    Log::channel('single')->info('plain');

    expect(file_get_contents($path))->not->toContain('abc-12345678')->and(file_get_contents($path))->not->toContain('request_id');
});

it('MNC-2 the only response change is the X-Request-Id header', function (): void {
    $with = $this->get('/api/ping');
    $without = $this->withoutMiddleware(AssignRequestId::class)->get('/api/ping');

    expect($with->headers->has('X-Request-Id'))->toBeTrue()
        ->and($without->headers->has('X-Request-Id'))->toBeFalse()
        ->and($with->getStatusCode())->toBe($without->getStatusCode())
        ->and($with->headers->get('Content-Type'))->toBe($without->headers->get('Content-Type'))
        ->and(md5((string) $with->getContent()))->toBe(md5((string) $without->getContent()));
});

it('F13 /api/ping writes no log entry even with debug routes on', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);
    config(['logging.default' => 'frankenphp', 'app.debug_routes' => true]);

    $response = $this->get('/api/ping')->assertOk();

    expect($calls)->toBe([])
        ->and($response->json('data'))->toBe(['ping' => 'pong']);
});

it('NFR-2 /debug/log-ping writes exactly one info entry to the default channel', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);
    config(['logging.default' => 'frankenphp', 'app.debug_routes' => true]);

    $response = $this->getJson('/debug/log-ping')->assertOk();

    expect($calls)->toHaveCount(1)
        ->and($calls[0][0])->toBe('ping')
        ->and($calls[0][2]['level_name'])->toBe('INFO')
        ->and($calls[0][2]['request']['route'])->toBe('debug.log-ping')
        ->and($response->json('data'))->toBe(['ping' => 'pong']);
});

it('NFR-2 /debug/log-ping is hidden and writes nothing while debug routes are off', function (): void {
    $calls = [];
    useFrankenphpChannel($calls);
    config(['logging.default' => 'frankenphp', 'app.debug_routes' => false]);

    $this->getJson('/debug/log-ping')->assertNotFound();

    expect(array_column($calls, 0))->not->toContain('ping');
});

it('AC-1 AC-4 a job run by queue:work logs with context queue and null request fields', function (): void {
    Route::get('/_log-dispatch', function () {
        LogProbeJob::dispatch()->onConnection('database');

        return response('queued');
    });

    $this->get('/_log-dispatch', ['X-Request-Id' => 'abc-12345678'])->assertOk();

    $calls = [];
    useFrankenphpChannel($calls);
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    expect($calls)->toHaveCount(1)
        ->and($calls[0][2]['request'])->toMatchArray([
            'context' => 'queue',
            'method' => null,
            'path' => null,
            'route' => null,
        ]);

    event(new Looping('database', 'default'));
    expect(Context::hasHidden(RequestContextProcessor::QUEUE_JOB_KEY))->toBeFalse();
});

it('AC-1 a sync job inside a request still logs with context http', function (): void {
    Route::get('/_log-sync', function () {
        LogProbeJob::dispatchSync();

        return response('done');
    });

    $calls = [];
    useFrankenphpChannel($calls);

    $this->get('/_log-sync')->assertOk();

    expect($calls)->toHaveCount(1)
        ->and($calls[0][2]['request'])->toMatchArray(['context' => 'http', 'method' => 'GET', 'path' => '/_log-sync']);
});

it('gap 12 frankenphp_log gets flat dotted attributes, because FrankenPHP prints nested maps as Map and Order', function (): void {
    $attributes = FrankenphpLogHandler::slogAttributes([
        'level_name' => 'INFO',
        'channel' => 'local',
        'request' => ['request_id' => 'abc-12345678', 'worker' => true, 'route' => null],
        'context' => ['user' => ['id' => 7, 'password' => '[redacted]'], 'tags' => ['a', 'b'], 'empty' => []],
    ]);

    expect($attributes)->toBe([
        'level_name' => 'INFO',
        'channel' => 'local',
        'request.request_id' => 'abc-12345678',
        'request.worker' => true,
        'request.route' => null,
        'context.user.id' => 7,
        'context.user.password' => '[redacted]',
        'context.tags.0' => 'a',
        'context.tags.1' => 'b',
    ]);
});
