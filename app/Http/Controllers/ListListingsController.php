<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ListListingsRequest;
use App\Http\Responses\ApiResponse;
use App\Services\ListingSearchService;
use Illuminate\Http\JsonResponse;

final class ListListingsController extends Controller
{
    public function __invoke(ListListingsRequest $request, ListingSearchService $service): JsonResponse
    {
        $page = $service->search($request->toFilter());

        return ApiResponse::success($page->itemsToArray(), 'Listings retrieved', meta: $page->meta());
    }
}
