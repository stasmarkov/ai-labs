<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\PlaygroundPromptSummary;
use App\Services\PlaygroundPromptService;
use Illuminate\Http\JsonResponse;

final class ListPlaygroundPromptsController extends Controller
{
    public function __invoke(PlaygroundPromptService $service): JsonResponse
    {
        return response()->json(array_map(fn (PlaygroundPromptSummary $prompt): array => $prompt->toArray(), $service->all()));
    }
}
