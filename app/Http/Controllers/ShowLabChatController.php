<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LabOverviewService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabChatController extends Controller
{
    public function __invoke(LabOverviewService $lab): Response
    {
        $slice = $lab->capability('chat')->slice;

        return Inertia::render('Lab/Chat', [
            'slices' => [$slice, 'SL-41'],
            'blockedBy' => null,
            'backlog' => "backlog.md › {$slice}",
            'guide' => 'docs/learning-guide.md',
        ]);
    }
}
