<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\VinReportService;
use Illuminate\Http\JsonResponse;

final class ShowSlowListingController extends Controller
{
    public function __invoke(int $id, VinReportService $service): JsonResponse
    {
        return new JsonResponse($service->reportFor($id)->toArray());
    }
}
