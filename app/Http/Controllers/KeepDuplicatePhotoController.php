<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PhotoNotInDuplicateGroupException;
use App\Http\Requests\KeepDuplicatePhotoRequest;
use App\Services\DuplicateReviewService;
use Illuminate\Http\JsonResponse;

final class KeepDuplicatePhotoController extends Controller
{
    public function __invoke(int $group, KeepDuplicatePhotoRequest $request, DuplicateReviewService $review): JsonResponse
    {
        try {
            $card = $review->keep($group, $request->photoId());
        } catch (PhotoNotInDuplicateGroupException $outside) {
            return response()->json([
                'message' => $outside->getMessage(),
                'errors' => ['photo_id' => ['The photo is not in this group.']],
            ], 422);
        }

        return response()->json(['group' => $card->toArray(), 'summary' => $review->summary()->toArray()]);
    }
}
