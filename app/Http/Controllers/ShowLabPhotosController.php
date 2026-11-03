<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class ShowLabPhotosController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Lab/Photos', [
            'slices' => ['SL-36'],
            'blockedBy' => 'Q-06',
            'backlog' => 'backlog.md',
            'guide' => 'docs/learning-guide.md',
        ]);
    }
}
