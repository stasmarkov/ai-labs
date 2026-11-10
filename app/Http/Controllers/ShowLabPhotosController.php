<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LabOverviewService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabPhotosController extends Controller
{
    public function __invoke(LabOverviewService $lab): Response
    {
        $capability = $lab->capability('photos');

        return Inertia::render('Lab/Photos', [
            'summary' => $capability->summary,
            'guideHref' => route('ai.guides.show', ['key' => $capability->guideKey], absolute: false).'#plan',
        ]);
    }
}
