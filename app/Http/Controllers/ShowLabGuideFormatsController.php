<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuideFormatPageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabGuideFormatsController extends Controller
{
    public function __invoke(string $key, GuideFormatPageService $service): Response
    {
        return Inertia::render('Lab/GuideFormats', $service->compare($key));
    }
}
