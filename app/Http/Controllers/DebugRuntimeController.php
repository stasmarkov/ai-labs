<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReflectionFunction;
use ReflectionParameter;

final class DebugRuntimeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(config('app.debug_routes') === true, 404);

        $opcacheEnabled = function_exists('opcache_get_status')
            && (opcache_get_status(false)['opcache_enabled'] ?? false) === true;

        return ApiResponse::success([
            'sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'opcache_enabled' => $opcacheEnabled,
            'xdebug_loaded' => extension_loaded('xdebug'),
            'server_software' => $request->server('SERVER_SOFTWARE'),
            'frankenphp_log' => function_exists('frankenphp_log') ? self::signatureOf('frankenphp_log') : null,
        ], 'runtime');
    }

    /**
     * The signature of a function as PHP reflects it, e.g. "str_contains(string $haystack, string $needle): bool". It
     * checks the frankenphp_log() the structured log channel calls, which exists only inside a FrankenPHP request.
     */
    public static function signatureOf(string $function): string
    {
        $reflection = new ReflectionFunction($function);
        $parameters = array_map(static function (ReflectionParameter $parameter): string {
            $text = trim(($parameter->getType() ?? '').' $'.$parameter->getName());
            if ($parameter->isDefaultValueAvailable()) {
                $default = $parameter->getDefaultValue();
                $text .= ' = '.($default === [] ? '[]' : strtolower(var_export($default, true)));
            }

            return $text;
        }, $reflection->getParameters());

        return $function.'('.implode(', ', $parameters).')'.($reflection->hasReturnType() ? ': '.$reflection->getReturnType() : '');
    }
}
