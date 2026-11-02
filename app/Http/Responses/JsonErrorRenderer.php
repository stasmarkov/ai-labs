<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Exceptions\SemanticSearchUnavailableException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class JsonErrorRenderer
{
    public static function wantsJson(Request $request): bool
    {
        return $request->is('api/*')
            || $request->routeIs('listings.index', 'listings.describe')
            || $request->is('listings/*/describe')
            || $request->expectsJson();
    }

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::wantsJson($request)) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => ApiResponse::error(422, 'Validation failed', $e->errors()),
            $e instanceof SemanticSearchUnavailableException => ApiResponse::error(503, $e->getMessage()),
            $e instanceof ModelNotFoundException => ApiResponse::error(404, 'Not found'),
            $e instanceof HttpExceptionInterface => ApiResponse::error(
                $e->getStatusCode(),
                $e->getStatusCode() === 404 ? 'Not found' : ($e->getMessage() !== '' ? $e->getMessage() : 'Request failed'),
            ),
            default => ApiResponse::error(500, 'Server error'),
        };
    }
}
