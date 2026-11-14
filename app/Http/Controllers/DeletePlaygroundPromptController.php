<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PlaygroundPromptException;
use App\Services\PlaygroundPromptService;
use Illuminate\Http\Response;

final class DeletePlaygroundPromptController extends Controller
{
    /**
     * @throws PlaygroundPromptException HTTP 404 for an unknown prompt
     */
    public function __invoke(int $id, PlaygroundPromptService $service): Response
    {
        $service->delete($id);

        return response()->noContent();
    }
}
