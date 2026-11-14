<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PlaygroundRunService;
use Illuminate\Http\JsonResponse;

final class ShowPlaygroundRunController extends Controller
{
    public function __invoke(string $id, PlaygroundRunService $service): JsonResponse
    {
        return response()->json(($service->find($id) ?? abort(404))->toArray());
    }
}
