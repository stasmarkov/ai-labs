<?php

declare(strict_types=1);

/**
 * Runs requests against the app in a separate PHP process and prints the responses as JSON.
 *
 * Usage: php octane-worker.php <worker|classic> '<json list of request URIs>'
 *
 * worker  boots Octane's Worker once (listeners from config/octane.php, FakeClient) and
 *         sends every request through it, so state can live across requests.
 * classic boots the application once and sends the given requests through the HTTP kernel;
 *         callers pass one request per process to get a fresh boot per request.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Worker;

$basePath = dirname(__DIR__, 2);

require $basePath.'/vendor/autoload.php';

[, $mode, $encodedUris] = $argv + [null, null, null];
$uris = json_decode((string) $encodedUris, true);

if (! in_array($mode, ['worker', 'classic'], true) || ! is_array($uris)) {
    fwrite(STDERR, "usage: octane-worker.php <worker|classic> '<json list of uris>'\n");
    exit(2);
}

$responses = [];

if ($mode === 'worker') {
    $_ENV['LARAVEL_OCTANE'] = $_SERVER['LARAVEL_OCTANE'] = '1';
    putenv('LARAVEL_OCTANE=1');

    $client = new FakeClient([]);
    $worker = new Worker(new ApplicationFactory($basePath), $client);
    $worker->boot();

    // Record each request's outcome right after it, so a worker error keeps its place in the A/B order.
    foreach ($uris as $uri) {
        $responsesBefore = count($client->responses);
        $errorsBefore = count($client->errors);
        $worker->handle(Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json']), new RequestContext);

        if (count($client->responses) > $responsesBefore) {
            $response = $client->responses[count($client->responses) - 1];
            $responses[] = ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
        } elseif (count($client->errors) > $errorsBefore) {
            $responses[] = ['status' => 500, 'body' => ['error' => (string) $client->errors[count($client->errors) - 1]]];
        } else {
            $responses[] = ['status' => 0, 'body' => ['error' => 'no response recorded']];
        }
    }
} else {
    $app = require $basePath.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);

    foreach ($uris as $uri) {
        $response = $kernel->handle(Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
        $responses[] = ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }
}

echo json_encode($responses);
