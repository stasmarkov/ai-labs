<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\ModerationResult;
use App\Exceptions\ModerationUnavailableException;
use App\Http\Requests\ModerateListingRequest;
use App\Http\Responses\ApiResponse;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;

final class ModerateListingController extends Controller
{
    /**
     * @throws ModerationUnavailableException HTTP 503 when the model could not be reached
     */
    public function __invoke(ModerateListingRequest $request, ModerationService $service): JsonResponse
    {
        $result = $service->moderate($request->toInput(), $request->provider());

        if ($result->status === ModerationResult::FAILED) {
            throw new ModerationUnavailableException($result->invocationIds, $result->attempts);
        }

        return ApiResponse::success($result->toArray(), 'Listing moderated');
    }
}
