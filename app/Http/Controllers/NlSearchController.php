<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\NlSearchRequest;
use App\Http\Responses\ApiResponse;
use App\Services\NlSearchService;
use Illuminate\Http\JsonResponse;

final class NlSearchController extends Controller
{
    public function __invoke(NlSearchRequest $request, NlSearchService $service): JsonResponse
    {
        return ApiResponse::success($service->search($request->toQuery())->toArray(), 'Listings retrieved');
    }
}
