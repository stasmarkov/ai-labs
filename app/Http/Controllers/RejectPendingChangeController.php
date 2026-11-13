<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DecidePendingChangeRequest;
use App\Http\Responses\ApiResponse;
use App\Services\PendingChangeDecisionService;
use App\Services\PendingChangePageService;
use Illuminate\Http\JsonResponse;

/**
 * Drops a proposed change without writing the listing.
 */
final class RejectPendingChangeController extends Controller
{
    public function __invoke(string $id, DecidePendingChangeRequest $request, PendingChangeDecisionService $decisions, PendingChangePageService $pages): JsonResponse
    {
        return ApiResponse::success($pages->present($decisions->reject($id, $request->nonce())));
    }
}
