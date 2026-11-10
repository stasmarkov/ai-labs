<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuidePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabGuideController extends Controller
{
    public function __invoke(string $key, GuidePageService $service): Response
    {
        return Inertia::render('Lab/Guide', ['guide' => $service->show($key)]);
    }
}
