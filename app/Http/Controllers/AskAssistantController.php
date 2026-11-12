<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AssistantAskRequest;
use App\Services\BuyerAssistantService;
use Illuminate\Contracts\Support\Responsable;

final class AskAssistantController extends Controller
{
    public function __invoke(AssistantAskRequest $request, BuyerAssistantService $service): Responsable
    {
        return $service->ask($request->question(), $request->history(), $request->provider());
    }
}
