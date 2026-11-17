<?php

declare(strict_types=1);

/*
 * A minimal DBGp listener: waits for one debugger connection from Xdebug and prints "connections=N init=N".
 * Usage: php bench/xdebug-check.php [--port=9003] [--wait=10] [--host=127.0.0.1] [--follow]
 * --follow acts like an IDE after the init packet: it sends "run" so the request goes on, keeps the connection open for
 * up to --wait seconds and adds "ended=1 after=<ms>" when Xdebug reports the end of the PHP script (status "stopping",
 * answered with "stop") or closes the connection, or "ended=0" when the session is still open. In a worker the script is
 * the whole worker, so ended=0 means later requests on that worker would run under the debugger.
 * It listens on the loopback address only, so no peer on the network can connect first; OrbStack (checked) and Docker
 * Desktop deliver host.docker.internal there. On Linux Docker pass the bridge address with --host.
 */

$options = getopt('', ['port::', 'wait::', 'host::', 'follow']);
$port = (int) ($options['port'] ?? 9003);
$wait = (int) ($options['wait'] ?? 10);
$host = (string) ($options['host'] ?? '127.0.0.1');
$follow = array_key_exists('follow', $options);

$server = @stream_socket_server("tcp://$host:$port", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "port $port in use, close the IDE listener\n");
    exit(1);
}

echo "listening on $port for {$wait}s\n";

$connections = 0;
$inits = 0;
$ended = null;
$client = @stream_socket_accept($server, $wait);
if ($client !== false) {
    $connections = 1;
    $deadline = microtime(true) + 2;
    $packet = '';
    stream_set_blocking($client, false);
    while (! str_contains($packet, '<init') && microtime(true) < $deadline && ! feof($client)) {
        $read = [$client];
        $write = $except = null;
        if (stream_select($read, $write, $except, 0, 100_000) > 0) {
            $packet .= (string) fread($client, 8192);
        }
    }
    $inits = str_contains($packet, '<init') ? 1 : 0;

    if ($follow && $inits === 1) {
        $ran = microtime(true);
        fwrite($client, "run -i 1\0");
        $ended = 0;
        $until = $ran + $wait;
        while (microtime(true) < $until) {
            $read = [$client];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, 50_000) < 1) {
                continue;
            }
            $data = (string) fread($client, 8192);
            if (str_contains($data, 'status="stopping"') || ($data === '' && feof($client))) {
                $ended = max(1, (int) round((microtime(true) - $ran) * 1000));
                @fwrite($client, "stop -i 2\0");
                break;
            }
        }
    }
    fclose($client);
}
fclose($server);

$summary = "connections=$connections init=$inits";
if ($ended !== null) {
    $summary .= $ended > 0 ? " ended=1 after={$ended}ms" : ' ended=0';
}
echo $summary."\n";
exit(0);
