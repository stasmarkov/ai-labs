<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PlaygroundPromptException;
use App\Http\Requests\AddPlaygroundVersionRequest;
use App\Services\PlaygroundPromptService;
use Illuminate\Http\JsonResponse;

final class AddPlaygroundVersionController extends Controller
{
    /**
     * @throws PlaygroundPromptException HTTP 404 for an unknown prompt, 422 `unchanged`, 409 `version_conflict`
     */
    public function __invoke(int $id, AddPlaygroundVersionRequest $request, PlaygroundPromptService $service): JsonResponse
    {
        return response()->json($service->addVersion($id, $request->toVersion())->toArray(), 201);
    }
}
