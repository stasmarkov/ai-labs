<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\NlSearchRequest;
use App\Services\NlSearchService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabNlSearchController extends Controller
{
    private const ID_PLACEHOLDER = 'RUN_ID';

    public function __invoke(): Response
    {
        return Inertia::render('Lab/NlSearch', [
            'searchUrl' => route('listings.nl', [], false),
            'runUrl' => route('ai.runs.show', ['invocationId' => self::ID_PLACEHOLDER], false),
            'idPlaceholder' => self::ID_PLACEHOLDER,
            'maxQueryLength' => NlSearchRequest::MAX_QUERY_LENGTH,
            'models' => NlSearchService::allowedModels(),
        ]);
    }
}
