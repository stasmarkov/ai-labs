<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PhotoExtractionInFlightException;
use App\Services\PhotoLabService;
use Illuminate\Http\JsonResponse;

final class ExtractLabPhotoController extends Controller
{
    public function __invoke(int $photo, PhotoLabService $lab): JsonResponse
    {
        try {
            return response()->json($lab->extract($photo)->toArray(), 202);
        } catch (PhotoExtractionInFlightException $inFlight) {
            return response()->json([...$inFlight->current->toArray(), 'message' => $inFlight->getMessage()], 409);
        }
    }
}
