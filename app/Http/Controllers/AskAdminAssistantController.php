<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AskAdminAssistantRequest;
use App\Http\Responses\ApiResponse;
use App\Services\AdminAssistantService;
use App\Support\RequestTimeLimit;
use Illuminate\Http\JsonResponse;

/**
 * Answers one question of an admin conversation; a change the model proposes is returned as a pending action, never applied.
 */
final class AskAdminAssistantController extends Controller
{
    public function __invoke(AskAdminAssistantRequest $request, AdminAssistantService $service): JsonResponse
    {
        RequestTimeLimit::atLeast(150);

        return ApiResponse::success($service->ask($request->conversationId(), $request->question())->toArray());
    }
}
