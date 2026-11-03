<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SemanticSearchRequest;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabSemanticController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Lab/Semantic', [
            'searchUrl' => route('listings.semantic', [], false),
            'maxQueryLength' => SemanticSearchRequest::MAX_QUERY_LENGTH,
        ]);
    }
}
