<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\GuideTeach;
use App\Services\GuidePageService;
use App\Services\PendingChangePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabChangesController extends Controller
{
    public function __invoke(PendingChangePageService $pages, GuidePageService $guides): Response
    {
        return Inertia::render('Lab/Changes', [
            ...$pages->lists(),
            'change' => null,
            'teach' => fn (): GuideTeach => $guides->teach('mcp'),
            ...ShowLabChangeController::urls(),
        ]);
    }
}
