<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\SimilarPhotoMatch;
use App\Http\Requests\PhotoTextSearchRequest;
use App\Http\Responses\ApiResponse;
use App\Services\PhotoTextSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

final class PhotoTextSearchController extends Controller
{
    public function __invoke(PhotoTextSearchRequest $request, PhotoTextSearchService $service): JsonResponse
    {
        $result = $service->search($request->searchText());

        return ApiResponse::success(
            array_map(fn (SimilarPhotoMatch $match): array => $match->toArray() + [
                'url' => (string) parse_url(Storage::disk('public')->url($match->path), PHP_URL_PATH),
                'score' => $match->similarity,
            ], $result->results),
            'Photos retrieved',
            meta: [
                'query' => $result->query,
                'prompt' => $result->prompt,
                'label' => $result->label,
                'min_score' => $result->minScore,
                'cached' => $result->cached,
            ],
        );
    }
}
