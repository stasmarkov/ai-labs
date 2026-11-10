<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PhotoLabService;
use Illuminate\Http\JsonResponse;

final class ShowLabPhotoStatusController extends Controller
{
    public function __invoke(int $photo, PhotoLabService $lab): JsonResponse
    {
        return response()->json($lab->status($photo)->toArray());
    }
}
