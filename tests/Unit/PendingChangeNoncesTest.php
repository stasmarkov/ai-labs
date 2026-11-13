<?php

declare(strict_types=1);

use App\Services\PendingChangeNonces;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function nonceStore(): Store
{
    return new Store('test', new ArraySessionHandler(120));
}

it('NFR-1 issues one random 32 character nonce per change and reuses it in the session', function (): void {
    $nonces = new PendingChangeNonces(nonceStore());

    $first = $nonces->issue('change-a');

    expect($first)->toHaveLength(32)
        ->and($nonces->issue('change-a'))->toBe($first)
        ->and($nonces->issue('change-b'))->not->toBe($first);
});

it('NFR-1 accepts a nonce once, only for its own change, and only in the session that got it', function (): void {
    $store = nonceStore();
    $nonces = new PendingChangeNonces($store);
    $nonce = $nonces->issue('change-a');
    $other = $nonces->issue('change-b');

    expect($nonces->consume('change-a', 'wrong'))->toBeFalse()
        ->and($nonces->consume('change-a', $other))->toBeFalse()
        ->and($nonces->consume('change-a', $nonce))->toBeTrue()
        ->and($nonces->consume('change-a', $nonce))->toBeFalse()
        ->and((new PendingChangeNonces(nonceStore()))->consume('change-b', $other))->toBeFalse();
});

it('NFR-1 keeps only the newest hundred nonces in the session', function (): void {
    $nonces = new PendingChangeNonces(nonceStore());
    $oldest = $nonces->issue('change-0');

    foreach (range(1, 100) as $number) {
        $nonces->issue('change-'.$number);
    }

    expect($nonces->consume('change-0', $oldest))->toBeFalse()
        ->and($nonces->consume('change-100', $nonces->issue('change-100')))->toBeTrue();
});
