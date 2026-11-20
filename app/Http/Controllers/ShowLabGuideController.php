<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GuideFormatPageService;
use App\Services\GuidePageService;
use App\Services\LessonPageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabGuideController extends Controller
{
    public function __invoke(string $key, GuidePageService $service, GuideFormatPageService $formats, LessonPageService $course): Response
    {
        return Inertia::render('Lab/Guide', [
            'guide' => $service->show($key),
            'formatsHref' => $formats->trialHref($key),
            ...$course->guideContext($key),
        ]);
    }
}
