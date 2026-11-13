<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PendingChangePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabChangesController extends Controller
{
    public function __invoke(PendingChangePageService $pages): Response
    {
        return Inertia::render('Lab/Changes', [
            ...$pages->lists(),
            'change' => null,
            ...ShowLabChangeController::urls(),
        ]);
    }
}
