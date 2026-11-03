<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class ShowLabChatController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Lab/Chat', [
            'slices' => ['SL-45', 'SL-41'],
            'blockedBy' => null,
            'backlog' => 'backlog.md',
            'guide' => 'docs/learning-guide.md',
        ]);
    }
}
