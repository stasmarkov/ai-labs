<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\PlaygroundRunSummary;
use App\Http\Requests\ListPlaygroundRunsRequest;
use App\Services\PlaygroundRunService;
use Illuminate\Http\JsonResponse;

final class ListPlaygroundRunsController extends Controller
{
    public function __invoke(ListPlaygroundRunsRequest $request, PlaygroundRunService $service): JsonResponse
    {
        return response()->json(array_map(fn (PlaygroundRunSummary $run): array => $run->toArray(), $service->recent($request->limit())));
    }
}
