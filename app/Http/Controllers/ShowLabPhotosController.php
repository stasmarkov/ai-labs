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
        $slice = $lab->capability('photos')->slice;

        return Inertia::render('Lab/Photos', [
            'slices' => [$slice],
            'blockedBy' => 'Q-06',
            'backlog' => "backlog.md › {$slice}",
            'guide' => 'docs/learning-guide.md',
        ]);
    }
}
