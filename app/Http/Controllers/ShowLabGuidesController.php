<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuidePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabGuidesController extends Controller
{
    public function __invoke(GuidePageService $service): Response
    {
        return Inertia::render('Lab/Guides', $service->index());
    }
}
