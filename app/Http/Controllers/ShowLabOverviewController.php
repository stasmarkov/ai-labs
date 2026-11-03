<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LabOverviewService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabOverviewController extends Controller
{
    public function __invoke(LabOverviewService $service): Response
    {
        return Inertia::render('Lab/Overview', $service->overview()->toProps());
    }
}
