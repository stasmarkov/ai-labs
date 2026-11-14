<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PlaygroundPromptException;
use App\Http\Requests\CreatePlaygroundPromptRequest;
use App\Services\PlaygroundPromptService;
use Illuminate\Http\JsonResponse;

final class CreatePlaygroundPromptController extends Controller
{
    /**
     * @throws PlaygroundPromptException HTTP 422 `invalid_name` or `name_taken`
     */
    public function __invoke(CreatePlaygroundPromptRequest $request, PlaygroundPromptService $service): JsonResponse
    {
        return response()->json($service->create($request->name(), $request->toVersion())->toArray(), 201);
    }
}
