<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ResearchRunService;
use Illuminate\Http\JsonResponse;

final class ShowResearchRunController extends Controller
{
    public function __invoke(string $id, ResearchRunService $service): JsonResponse
    {
        return response()->json($service->find($id) ?? abort(404));
    }
}
