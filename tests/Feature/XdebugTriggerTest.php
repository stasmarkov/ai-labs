<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function xdebugCheckPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $port;
}

it('AC-4 and MNC-3 ship trigger-mode settings so only a triggered request connects', function (): void {
    $ini = parse_ini_file(base_path('.ddev/php/xdebug-trigger.ini'));

    expect($ini['xdebug.start_with_request'])->toBe('trigger')
        ->and($ini['xdebug.client_host'])->toBe('host.docker.internal')
        ->and((int) $ini['xdebug.client_port'])->toBe(9003)
        ->and(array_key_exists('xdebug.mode', $ini))->toBeFalse();
});

it('AC-4 xdebug-check reports the init packet of a connecting debugger client', function (): void {
    $port = xdebugCheckPort();
    $listener = new Process(['php', base_path('bench/xdebug-check.php'), "--port=$port", '--wait=5']);
    $listener->start();
    $deadline = microtime(true) + 5;
    do {
        usleep(100_000);
        $client = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 1);
    } while (! $client && microtime(true) < $deadline);
    fwrite($client, "<init fileuri=\"file:///var/www/html/public/index.php\"/>\0");
    fclose($client);
    $listener->wait();

    expect($listener->getExitCode())->toBe(0)
        ->and($listener->getOutput())->toContain('connections=1')
        ->and($listener->getOutput())->toContain('init=1');
});

it('MNC-3 xdebug-check reports no connection when nothing connects', function (): void {
    $listener = new Process(['php', base_path('bench/xdebug-check.php'), '--port='.xdebugCheckPort(), '--wait=1']);
    $listener->run();

    expect($listener->getExitCode())->toBe(0)
        ->and($listener->getOutput())->toContain('connections=0');
});

it('AC-4 xdebug-check exits 1 at once when the port is taken', function (): void {
    $server = stream_socket_server('tcp://0.0.0.0:0', $errno, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $listener = new Process(['php', base_path('bench/xdebug-check.php'), "--port=$port", '--wait=5']);
    $listener->run();
    fclose($server);

    expect($listener->getExitCode())->toBe(1)
        ->and($listener->getOutput().$listener->getErrorOutput())->toContain('port '.$port.' in use, close the IDE listener');
});
