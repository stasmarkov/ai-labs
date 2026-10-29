<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<string, mixed>|null  $meta
     */
    public static function success(array $data, string $message = 'OK', int $status = 200, ?array $meta = null): JsonResponse
    {
        $body = [
            'success' => true,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function error(int $status, string $message, array $errors = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'status' => $status,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }
}
