<?php

declare(strict_types=1);

use App\Support\AdminApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'ai.server.mode' => 'frankenphp-worker',
        'ai.server.in_container' => true,
        'ai.server.admin_url' => 'http://localhost:2019',
    ]);
});

test('AC-6 worker mode posts to the admin restart endpoint and reports the time', function () {
    Http::fake(['localhost:2019/*' => Http::response('', 200)]);

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('workers restarted in')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://localhost:2019/frankenphp/workers/restart');
    Http::assertSentCount(2);
});

test('AC-6 a non-2xx admin answer exits 1 with the status', function () {
    Http::fake(['localhost:2019/*' => fn (Request $request) => $request->method() === 'GET'
        ? Http::response('{}', 200)
        : Http::response('boom', 500)]);

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('admin API: 500')
        ->assertExitCode(1);
});

test('AC-6 an admin API that drops the restart call exits 1 with the error', function () {
    Http::fake(['localhost:2019/*' => fn (Request $request) => $request->method() === 'GET'
        ? Http::response('{}', 200)
        : throw new ConnectionException('Connection refused')]);

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('admin API: Connection refused')
        ->assertExitCode(1);
});

test('AC-6 octane mode runs octane:reload and propagates its exit code', function (int $code) {
    config(['ai.server.mode' => 'frankenphp-octane']);
    Http::fake();
    Artisan::command('octane:reload', function () use ($code) {
        $this->line('octane reloaded');

        return $code;
    });

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('octane reloaded')
        ->assertExitCode($code);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
})->with([0, 1]);

test('AC-6 classic mode has nothing to restart and exits 0', function () {
    config(['ai.server.mode' => 'frankenphp']);
    Http::fake();

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('classic mode has no workers to restart')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

test('AC-6 an unknown mode exits 1', function () {
    config(['ai.server.mode' => 'unknown']);
    Http::fake();

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('server mode unknown')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

test('F11 MNC-4 outside the web container the command refuses before any probe', function (string $mode) {
    config(['ai.server.in_container' => false, 'ai.server.mode' => $mode]);
    Http::fake(['*' => Http::response('', 200)]);
    Artisan::command('octane:reload', fn () => 0);

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('web container')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with(['frankenphp-worker', 'frankenphp-octane']);

test('F8 inside the container an admin API that does not answer the probe stops the command before the restart', function () {
    Http::fake(['localhost:2019/*' => fn () => throw new ConnectionException('Connection refused')]);

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('admin API')
        ->assertExitCode(1);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('F8 inside the container the probe is sent before the restart', function () {
    Http::fake(['localhost:2019/*' => Http::response('', 200)]);

    $this->artisan('frankenphp:workers:restart')->assertExitCode(0);

    $methods = Http::recorded()->map(fn (array $pair): string => $pair[0]->method())->all();
    expect($methods)->toBe(['GET', 'POST']);
});

test('MNC-3 the admin URL stays on localhost', function () {
    expect(config('ai.server.admin_url'))->toStartWith('http://localhost:2019');
});

test('MNC-4 every restart path runs with the HTTP layer faked and no stray request', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $this->artisan('frankenphp:workers:restart')->assertExitCode(0);

    Http::assertSentCount(2);
});

test('AC-6 the container flag reads the DDEV environment value', function () {
    $config = require base_path('config/ai.php');

    expect($config['server']['in_container'])->toBe((bool) env('IS_DDEV_PROJECT', false));
});

test('F8 a non-2xx answer on the admin port does not pass the guard', function () {
    Http::fake(['localhost:2019/*' => Http::response('', 404)]);

    $this->artisan('frankenphp:workers:restart')
        ->expectsOutputToContain('admin API')
        ->assertExitCode(1);

    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('F19 the admin API client probes and restarts through the configured URL', function () {
    Http::fake(['localhost:2019/*' => Http::response('', 200)]);
    $client = app(AdminApiClient::class);

    expect($client->answers())->toBeTrue()
        ->and($client->restartWorkers(3)->successful())->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'http://localhost:2019/config/');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'http://localhost:2019/frankenphp/workers/restart');
});

test('F19 the admin API client reports a connection error as not answering', function () {
    Http::fake(['localhost:2019/*' => fn () => throw new ConnectionException('Connection refused')]);

    expect(app(AdminApiClient::class)->answers())->toBeFalse();
});
