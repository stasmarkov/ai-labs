<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\SemanticMatch;
use App\Http\Requests\SemanticSearchRequest;
use App\Http\Responses\ApiResponse;
use App\Services\SemanticSearchService;
use Illuminate\Http\JsonResponse;

final class SemanticSearchController extends Controller
{
    public function __invoke(SemanticSearchRequest $request, SemanticSearchService $service): JsonResponse
    {
        $matches = $service->search($request->searchText());

        return ApiResponse::success(
            array_map(fn (SemanticMatch $match): array => $match->toArray(), $matches),
            'Listings retrieved',
        );
    }
}
