<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PlaygroundPromptException;
use App\Services\PlaygroundPromptService;
use Illuminate\Http\JsonResponse;

final class ShowPlaygroundPromptController extends Controller
{
    /**
     * @throws PlaygroundPromptException HTTP 404 for an unknown prompt
     */
    public function __invoke(int $id, PlaygroundPromptService $service): JsonResponse
    {
        return response()->json($service->find($id)->toArray());
    }
}
