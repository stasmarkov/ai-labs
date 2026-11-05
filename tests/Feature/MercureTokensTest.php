<?php

declare(strict_types=1);

use App\Support\MercureTokens;

beforeEach(function (): void {
    config([
        'mercure.publisher_jwt_key' => 'publisher-key-for-tests',
        'mercure.subscriber_jwt_key' => 'subscriber-key-for-tests',
    ]);
    $this->tokens = new MercureTokens;
});

test('AC-3 the publisher token is HS256 with a publish claim for the topic and a short expiry', function () {
    $token = $this->tokens->publisher(['https://x.test/listings/1/price']);

    $claims = $this->tokens->verify($token, 'publisher-key-for-tests');
    $header = json_decode(base64_decode(strtr(explode('.', $token)[0], '-_', '+/')), true);

    expect($header)->toBe(['alg' => 'HS256', 'typ' => 'JWT'])
        ->and($claims['mercure'])->toBe(['publish' => ['https://x.test/listings/1/price']])
        ->and($claims['exp'] - $claims['iat'])->toBeLessThanOrEqual(60);
});

test('AC-3 the subscriber token names one topic and expires within an hour', function () {
    $token = $this->tokens->subscriber(['https://x.test/listings/1/price/private']);

    $claims = $this->tokens->verify($token, 'subscriber-key-for-tests');

    expect($claims['mercure'])->toBe(['subscribe' => ['https://x.test/listings/1/price/private']])
        ->and($claims['exp'] - $claims['iat'])->toBeLessThanOrEqual(3600);
});

test('AC-3 a token signed with another key is rejected', function () {
    $token = $this->tokens->publisher(['t']);

    expect($this->tokens->verify($token, 'subscriber-key-for-tests'))->toBeNull();
});

test('AC-3 a tampered payload is rejected', function () {
    [$header, , $signature] = explode('.', $this->tokens->subscriber(['a']));
    $forged = rtrim(strtr(base64_encode(json_encode(['mercure' => ['subscribe' => ['*']], 'exp' => time() + 99])), '+/', '-_'), '=');

    expect($this->tokens->verify("{$header}.{$forged}.{$signature}", 'subscriber-key-for-tests'))->toBeNull();
});

test('AC-3 an expired token is rejected', function () {
    $token = $this->tokens->sign(['mercure' => ['publish' => ['t']]], 'publisher-key-for-tests', -5);

    expect($this->tokens->verify($token, 'publisher-key-for-tests'))->toBeNull();
});

test('AC-3 malformed tokens are rejected', function () {
    expect($this->tokens->verify('not-a-token', 'k'))->toBeNull()
        ->and($this->tokens->verify('a.b.c', 'k'))->toBeNull();
});

test('AC-3 signing without a configured key fails loudly', function () {
    config(['mercure.publisher_jwt_key' => null]);

    $this->tokens->publisher(['t']);
})->throws(RuntimeException::class);

test('AC-3 exp minus iat is exactly the ttl because the clock is read once', function () {
    foreach (range(1, 300) as $_) {
        $publisher = $this->tokens->verify($this->tokens->publisher(['t']), 'publisher-key-for-tests');
        $subscriber = $this->tokens->verify($this->tokens->subscriber(['t']), 'subscriber-key-for-tests');

        expect($publisher['exp'] - $publisher['iat'])->toBe(60)
            ->and($subscriber['exp'] - $subscriber['iat'])->toBe(3600);
    }
});

test('AC-3 a given issue time is used for both iat and exp', function () {
    $claims = $this->tokens->verify($this->tokens->subscriber(['t'], time() + 5), 'subscriber-key-for-tests');

    expect($claims['exp'] - $claims['iat'])->toBe(3600)->and($claims['iat'])->toBeGreaterThan(time());
});
