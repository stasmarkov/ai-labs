<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Context;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RequestContextProcessor implements ProcessorInterface
{
    public const string REQUEST_ID_KEY = 'request_id';

    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['request'] = $this->requestFields();

        return $record;
    }

    /**
     * @return array{request_id: ?string, method: ?string, path: ?string, route: ?string, server_mode: string, worker: bool, elapsed_ms: ?int, context: string}
     */
    private function requestFields(): array
    {
        $requestId = Context::getHidden(self::REQUEST_ID_KEY);
        $serverMode = (string) config('ai.server.mode', 'unknown');
        $worker = isset($_SERVER['FRANKENPHP_WORKER']) || isset($_SERVER['LARAVEL_OCTANE']);

        if (! is_string($requestId) || $requestId === '') {
            return [
                'request_id' => null,
                'method' => null,
                'path' => null,
                'route' => null,
                'server_mode' => $serverMode,
                'worker' => $worker,
                'elapsed_ms' => null,
                'context' => 'cli',
            ];
        }

        $request = request();
        $route = $request->route();
        $started = $request->server('REQUEST_TIME_FLOAT');

        return [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $route === null ? null : ($route->getName() ?? $route->uri()),
            'server_mode' => $serverMode,
            'worker' => $worker,
            'elapsed_ms' => is_numeric($started) ? (int) round((microtime(true) - (float) $started) * 1000) : null,
            'context' => 'http',
        ];
    }
}
