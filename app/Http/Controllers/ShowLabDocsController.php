<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DocsSearchService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabDocsController extends Controller
{
    public function __invoke(DocsSearchService $service): Response
    {
        return Inertia::render('Lab/Docs', [
            'searchUrl' => route('docs.search', [], false),
            'maxQueryLength' => DocsSearchService::MAX_QUERY_LENGTH,
            'k' => $service->defaultK(),
            'maxK' => $service->maxK(),
        ]);
    }
}
