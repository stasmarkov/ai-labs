<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PhotoSimilarRequest;
use App\Services\PhotoLabService;
use Illuminate\Http\JsonResponse;

final class ShowLabPhotoSimilarController extends Controller
{
    public function __invoke(int $photo, PhotoSimilarRequest $request, PhotoLabService $lab): JsonResponse
    {
        $detail = $lab->status($photo);

        return response()->json($lab->similar($detail, $request->weight())->toHybridArray());
    }
}
