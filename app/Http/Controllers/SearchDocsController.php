<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\DocsMatch;
use App\Http\Requests\DocsSearchRequest;
use App\Http\Responses\ApiResponse;
use App\Services\DocsSearchService;
use Illuminate\Http\JsonResponse;

final class SearchDocsController extends Controller
{
    public function __invoke(DocsSearchRequest $request, DocsSearchService $service): JsonResponse
    {
        $matches = $service->search($request->searchText(), $request->mode(), $request->limit());

        return ApiResponse::success(
            array_map(fn (DocsMatch $match): array => $match->toArray(), $matches),
            'Chunks retrieved',
        );
    }
}
