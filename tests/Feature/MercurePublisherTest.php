<?php

declare(strict_types=1);

use App\Support\MercurePublisher;
use App\Support\MercureTokens;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'mercure.publisher_jwt_key' => 'publisher-key-for-tests',
        'mercure.publish_url' => 'http://hub.test/.well-known/mercure',
    ]);
    Http::preventStrayRequests();
});

test('MNC-5 a public update posts topic and data with a publisher bearer token', function () {
    Http::fake(['hub.test/*' => Http::response('urn:uuid:1', 200)]);

    $sent = app(MercurePublisher::class)->publish('https://x.test/listings/1/price', ['id' => 1, 'price_cents' => 500]);

    expect($sent)->toBeTrue();
    Http::assertSent(function (Request $request) {
        $token = str_replace('Bearer ', '', $request->header('Authorization')[0]);
        $claims = (new MercureTokens)->verify($token, 'publisher-key-for-tests');

        return $request->url() === 'http://hub.test/.well-known/mercure'
            && $request['topic'] === 'https://x.test/listings/1/price'
            && json_decode($request['data'], true) === ['id' => 1, 'price_cents' => 500]
            && ! isset($request['private'])
            && $claims['mercure']['publish'] === ['https://x.test/listings/1/price'];
    });
});

test('AC-2 a private update sends private=on', function () {
    Http::fake(['hub.test/*' => Http::response('urn:uuid:2', 200)]);

    app(MercurePublisher::class)->publish('t', ['a' => 1], private: true);

    Http::assertSent(fn (Request $request) => $request['private'] === 'on');
});

test('AC-4 a hub error status is reported and not thrown', function () {
    Http::fake(['hub.test/*' => Http::response('nope', 500)]);

    $sent = app(MercurePublisher::class)->publish('t', []);

    expect($sent)->toBeFalse();
});

test('AC-4 an unreachable hub is reported and not thrown', function () {
    Http::fake(['hub.test/*' => fn () => throw new ConnectionException('down')]);

    expect(app(MercurePublisher::class)->publish('t', []))->toBeFalse();
});

test('AC-4 a missing key is reported and not thrown', function () {
    config(['mercure.publisher_jwt_key' => null]);
    Http::fake();

    expect(app(MercurePublisher::class)->publish('t', []))->toBeFalse();
    Http::assertNothingSent();
});

test('MNC-4 the failure is handed to the exception reporter', function () {
    Http::fake(['hub.test/*' => Http::response('nope', 503)]);
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e;

        return false;
    });

    app(MercurePublisher::class)->publish('t', []);

    expect($reported)->toHaveCount(1);
});

test('AC-4 the hub call waits 1 s to connect and 2 s in total', function () {
    $options = null;
    Http::fake(['hub.test/*' => function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response('urn:uuid:3', 200);
    }]);

    app(MercurePublisher::class)->publish('t', []);

    expect(config('mercure.connect_timeout'))->toBe(1)
        ->and(config('mercure.timeout'))->toBe(2)
        ->and($options['connect_timeout'])->toBe(1)
        ->and($options['timeout'])->toBe(2);
});

test('AC-4 every publish carries its own id', function () {
    $ids = [];
    Http::fake(['hub.test/*' => function (Request $request) use (&$ids) {
        $ids[] = $request['id'];

        return Http::response('ok', 200);
    }]);

    app(MercurePublisher::class)->publish('t', ['a' => 1]);
    app(MercurePublisher::class)->publish('t', ['a' => 1]);

    expect($ids)->toHaveCount(2)->and($ids[0])->toStartWith('urn:uuid:')->and($ids[0])->not->toBe($ids[1]);
});

test('AC-4 data that cannot be encoded as JSON is reported and not thrown', function () {
    Http::fake();
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e;

        return false;
    });

    $sent = app(MercurePublisher::class)->publish('t', ['broken' => "\xB1\x31"]);

    expect($sent)->toBeFalse()->and($reported)->toHaveCount(1)->and($reported[0])->toBeInstanceOf(JsonException::class);
    Http::assertNothingSent();
});
