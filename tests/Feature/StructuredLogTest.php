<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Logging\FrankenphpLogHandler;
use App\Logging\RequestContextProcessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Logger;

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
