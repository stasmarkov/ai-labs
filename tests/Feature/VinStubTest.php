<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function vinstubPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

function startVinstub(int $port, array $env = []): Process
{
    $process = new Process(['node', base_path('.ddev/vinstub/server.mjs')], null, ['PORT' => (string) $port] + $env);
    $process->start();

    for ($i = 0; $i < 50; $i++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($conn !== false) {
            fclose($conn);

            return $process;
        }
        usleep(100_000);
    }

    $process->stop();
    test()->fail('vinstub did not start: '.$process->getErrorOutput());
}

function vinstubGet(int $port, string $path): array
{
    $start = hrtime(true);
    $body = file_get_contents("http://127.0.0.1:{$port}{$path}");

    return [(hrtime(true) - $start) / 1e6, $body];
}

test('AC-3 vinstub answers /vin/TEST with a median delay of 280-320 ms', function (): void {
    $port = vinstubPort();
    $process = startVinstub($port);

    try {
        $times = [];
        for ($i = 0; $i < 10; $i++) {
            [$ms, $body] = vinstubGet($port, '/vin/TEST');
            $times[] = $ms;
            expect(json_decode($body, true))->toHaveKey('vin', 'TEST');
        }
        sort($times);
        $median = ($times[4] + $times[5]) / 2;

        expect($median)->toBeGreaterThanOrEqual(280)->toBeLessThanOrEqual(320);
    } finally {
        $process->stop();
    }
})->skip(fn () => (new Process(['node', '--version']))->run() !== 0, 'node is not available');

test('AC-3 AC-1 vinstub replies with the exact Q7 body for /vin/TEST', function (): void {
    $port = vinstubPort();
    $process = startVinstub($port, ['VINSTUB_DELAY_MS' => '10']);

    try {
        [, $body] = vinstubGet($port, '/vin/TEST');

        expect(json_decode($body, true))->toBe([
            'vin' => 'TEST',
            'make_check' => 'ok',
            'recalls' => 0,
            'source' => 'vinstub',
        ]);
    } finally {
        $process->stop();
    }
})->skip(fn () => (new Process(['node', '--version']))->run() !== 0, 'node is not available');

test('AC-3 vinstub honours VINSTUB_DELAY_MS', function (): void {
    $port = vinstubPort();
    $process = startVinstub($port, ['VINSTUB_DELAY_MS' => '50']);

    try {
        [$ms] = vinstubGet($port, '/vin/TEST');
        expect($ms)->toBeGreaterThanOrEqual(45)->toBeLessThan(200);
    } finally {
        $process->stop();
    }
})->skip(fn () => (new Process(['node', '--version']))->run() !== 0, 'node is not available');

test('AC-3 vinstub answers 400 json for a malformed percent-encoded vin and keeps serving', function (): void {
    $port = vinstubPort();
    $process = startVinstub($port, ['VINSTUB_DELAY_MS' => '10']);

    try {
        $body = @file_get_contents("http://127.0.0.1:{$port}/vin/%E0%A4%A", false, stream_context_create(['http' => ['ignore_errors' => true]]));

        expect($http_response_header[0])->toContain('400')
            ->and(json_decode((string) $body, true))->toBe(['message' => 'bad request']);
        [, $after] = vinstubGet($port, '/vin/TEST');
        expect(json_decode($after, true))->toHaveKey('vin', 'TEST');
    } finally {
        $process->stop();
    }
})->skip(fn () => (new Process(['node', '--version']))->run() !== 0, 'node is not available');

test('AC-3 vinstub compose service is reachable as vinstub on port 3000', function (): void {
    $compose = file_get_contents(base_path('.ddev/docker-compose.vinstub.yaml'));

    expect($compose)
        ->toContain('vinstub:')
        ->toContain('server.mjs')
        ->toContain('VINSTUB_DELAY_MS')
        ->toContain('3000')
        ->toContain('restart: unless-stopped');
});
