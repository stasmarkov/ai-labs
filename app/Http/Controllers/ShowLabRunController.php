<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LabDescribeService;
use Illuminate\Http\JsonResponse;

final class ShowLabRunController extends Controller
{
    public function __invoke(string $invocationId, LabDescribeService $service): JsonResponse
    {
        return response()->json($service->run($invocationId)->toArray());
    }
}
