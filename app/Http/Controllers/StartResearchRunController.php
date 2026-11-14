<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StartResearchRunRequest;
use App\Services\ResearchRunService;
use Illuminate\Http\JsonResponse;

final class StartResearchRunController extends Controller
{
    public function __invoke(StartResearchRunRequest $request, ResearchRunService $service): JsonResponse
    {
        $run = $service->start($request->topic(), $request->provider());
        $pattern = $service->refusedPattern($run);

        if ($pattern !== null) {
            return response()->json(['id' => $run->id, 'status' => $run->status, 'pattern' => $pattern]);
        }

        return response()->json(['id' => $run->id, 'status' => $run->status], 202);
    }
}
