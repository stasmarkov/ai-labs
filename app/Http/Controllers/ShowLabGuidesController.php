<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuideFormatPageService;
use App\Services\GuidePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabGuidesController extends Controller
{
    public function __invoke(GuidePageService $service, GuideFormatPageService $formats): Response
    {
        return Inertia::render('Lab/Guides', $service->index() + ['trial' => $formats->trial()]);
    }
}
