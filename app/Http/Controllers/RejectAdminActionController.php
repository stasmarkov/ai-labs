<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DecideAdminActionRequest;
use App\Http\Responses\ApiResponse;
use App\Services\AdminAssistantService;
use App\Support\RequestTimeLimit;
use Illuminate\Http\JsonResponse;

/**
 * Drops the pending action of a conversation without writing, then lets the model continue.
 */
final class RejectAdminActionController extends Controller
{
    public function __invoke(DecideAdminActionRequest $request, AdminAssistantService $service): JsonResponse
    {
        RequestTimeLimit::atLeast(150);

        return ApiResponse::success($service->decide($request->conversationId(), $request->actionId(), false)->toArray());
    }
}
