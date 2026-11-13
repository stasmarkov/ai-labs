<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DecidePendingChangeRequest;
use App\Http\Responses\ApiResponse;
use App\Services\PendingChangeDecisionService;
use App\Services\PendingChangePageService;
use Illuminate\Http\JsonResponse;

/**
 * Applies a proposed change after the developer clicked Confirm: the only route that writes a proposal.
 */
final class ConfirmPendingChangeController extends Controller
{
    public function __invoke(string $id, DecidePendingChangeRequest $request, PendingChangeDecisionService $decisions, PendingChangePageService $pages): JsonResponse
    {
        return ApiResponse::success($pages->present($decisions->confirm($id, $request->nonce())));
    }
}
