<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PlaygroundRunException;
use App\Http\Requests\RunPlaygroundRequest;
use App\Services\PlaygroundRunService;
use Illuminate\Http\JsonResponse;

final class RunPlaygroundController extends Controller
{
    /**
     * @throws PlaygroundRunException HTTP 422 with a stable code, before any target is called
     */
    public function __invoke(RunPlaygroundRequest $request, PlaygroundRunService $service): JsonResponse
    {
        return response()->json($service->run($request->toRunRequest())->toArray());
    }
}
