<?php

// The worker script of frankenphp-worker and frankenphp-octane (Octane keeps this file because it exists). It is
// Octane's bin/frankenphp-worker.php with one change: the request loop is App\Support\WorkerRecycle::loop(), so a request
// can end its worker once the response is sent (the Xdebug trigger middleware does, to close the debugger session).
// FrankenPHP then starts a fresh worker on that thread, as it does when the request limit is reached.

use App\Support\WorkerRecycle;
use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\FrankenPhp\FrankenPhpClient;
use Laravel\Octane\RequestContext;
use Laravel\Octane\Stream;
use Laravel\Octane\Worker;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\HttpFoundation\Response;

// Not in Octane's stub. Laravel's /up page prints "rendered in Nms" only when LARAVEL_START is
// defined. In worker mode a constant is set once per worker, so this measures time since the
// worker booted, not the request. Kept so /up has the same shape as on the other servers.
defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));

// Set a default for the application base path and public path if they are missing...
$_SERVER['APP_BASE_PATH'] = $_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? __DIR__.'/..';
$_SERVER['APP_PUBLIC_PATH'] = $_ENV['APP_PUBLIC_PATH'] ?? $_SERVER['APP_PUBLIC_PATH'] ?? __DIR__;

if ((! ($_SERVER['FRANKENPHP_WORKER'] ?? false)) || ! function_exists('frankenphp_handle_request')) {
    echo 'FrankenPHP must be in worker mode to use this script.';

    exit(1);
}

ignore_user_abort(true);

$basePath = require __DIR__.'/../vendor/laravel/octane/bin/bootstrap.php';

$frankenPhpClient = new FrankenPhpClient;

try {
    $worker = tap(new Worker(
        new ApplicationFactory($basePath), $frankenPhpClient
    ))->boot();
} catch (Throwable $e) {
    try {
        $container = Container::getInstance();

        if ($container->bound(ExceptionHandler::class)) {
            $container->make(ExceptionHandler::class)
                ->renderForConsole(new ConsoleOutput, $e);
        } else {
            fwrite(STDERR, sprintf(
                "[octane bootstrap] %s: %s\n  in %s:%d\n%s\n",
                $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
            ));
        }
    } catch (Throwable) {
        fwrite(STDERR, sprintf(
            "[octane bootstrap] %s: %s\n  in %s:%d\n%s\n",
            $e::class, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
        ));
    }

    exit(1);
}

$debugMode = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? 'false';
$maxRequests = (int) ($_ENV['MAX_REQUESTS'] ?? $_SERVER['MAX_REQUESTS'] ?? 1000);
$requestMaxExecutionTime = $_ENV['REQUEST_MAX_EXECUTION_TIME'] ?? $_SERVER['REQUEST_MAX_EXECUTION_TIME'] ?? null;

if (PHP_OS_FAMILY === 'Linux' && ! is_null($requestMaxExecutionTime)) {
    set_time_limit((int) $requestMaxExecutionTime);
}

try {
    $handleRequest = static function () use ($worker, $frankenPhpClient, $debugMode) {
        try {
            [$request, $context] = $frankenPhpClient->marshalRequest(new RequestContext);

            $worker->handle($request, $context);
        } catch (Throwable $e) {
            report($e);

            $response = new Response(
                $debugMode === 'true' ? (string) $e : 'Internal Server Error',
                500,
                [
                    'Status' => '500 Internal Server Error',
                    'Content-Type' => 'text/plain',
                ],
            );

            $response->send();

            Stream::shutdown($e);
        }
    };

    WorkerRecycle::loop(static fn (): bool => (bool) @frankenphp_handle_request($handleRequest), $maxRequests);
} finally {
    $worker->terminate();

    gc_collect_cycles();
}
