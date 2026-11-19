<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LessonPageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabLessonController extends Controller
{
    public function __invoke(string $module, string $lesson, LessonPageService $service, ?string $step = null): Response
    {
        return Inertia::render('Lab/Lesson', $service->show($module, $lesson, $step === null ? null : (int) $step));
    }
}
