<?php

declare(strict_types=1);

use Laravel\Ai\Streaming\Protocols\StreamProtocol;

/**
 * SseProtocol::chunks() mirrors the loop inside StreamProtocol::response() so the transport can look at the client
 * between chunks. If the SDK changes that method, this fails: review the fork against the new version, then update
 * the hash (and the version in the SseProtocol::chunks() docblock).
 */
const SSE_FORK_VENDOR_VERSION = 'laravel/ai v1.3.0';

const SSE_FORK_VENDOR_BODY_SHA256 = '581c094982f16d69f10b12495d8aa13f35e90f23fd7cad5863edfc067032d761';

it('the vendor StreamProtocol::response() that SseProtocol forks is unchanged', function (): void {
    $method = new ReflectionMethod(StreamProtocol::class, 'response');
    $lines = file((string) $method->getFileName(), FILE_IGNORE_NEW_LINES);
    $source = implode("\n", array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    expect(hash('sha256', $source))->toBe(SSE_FORK_VENDOR_BODY_SHA256, 'The SDK changed StreamProtocol::response() ('.SSE_FORK_VENDOR_VERSION.' was reviewed); review App\Ai\SseProtocol::chunks() against it.');
});
