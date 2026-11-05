<?php

declare(strict_types=1);

use App\Support\MercureTokens;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    config([
        'mercure.subscriber_jwt_key' => 'subscriber-key-for-tests',
        'mercure.topic_base' => 'https://x.test',
    ]);
});

test('AC-2 mercure:token prints a subscriber token naming the private topic of one listing', function () {
    $exit = Artisan::call('mercure:token', ['listing' => 7]);
    $token = trim(Artisan::output());
    $claims = (new MercureTokens)->verify($token, 'subscriber-key-for-tests');

    expect($exit)->toBe(0)
        ->and($claims['mercure'])->toBe(['subscribe' => ['https://x.test/listings/7/price/private']]);
});

test('AC-2 mercure:token has no --private option (it only issues private-topic tokens) and refuses a bad listing id', function () {
    expect(Artisan::all()['mercure:token']->getDefinition()->hasOption('private'))->toBeFalse()
        ->and(Artisan::all()['mercure:token']->getDescription())->toContain('private');
    $this->artisan('mercure:token', ['listing' => 'abc'])->assertExitCode(2);
});

test('AC-2 mercure:token fails when the subscriber key is not set', function () {
    config(['mercure.subscriber_jwt_key' => null]);

    $this->artisan('mercure:token', ['listing' => 7])->assertFailed();
});
