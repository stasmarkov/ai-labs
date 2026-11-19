<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LessonPageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabLearnController extends Controller
{
    public function __invoke(LessonPageService $service): Response
    {
        return Inertia::render('Lab/Learn', $service->course());
    }
}
