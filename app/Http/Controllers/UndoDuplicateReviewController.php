<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DuplicateReviewService;
use Illuminate\Http\JsonResponse;

final class UndoDuplicateReviewController extends Controller
{
    public function __invoke(int $group, DuplicateReviewService $review): JsonResponse
    {
        $card = $review->undo($group);

        return response()->json(['group' => $card->toArray(), 'summary' => $review->summary()->toArray()]);
    }
}
