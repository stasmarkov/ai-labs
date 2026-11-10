<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LabHomeService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabHomeController extends Controller
{
    public function __invoke(LabHomeService $service): Response
    {
        return Inertia::render('Home', $service->home()->toProps());
    }
}
