<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DocsAskRequest;
use App\Services\DocsAnswerService;
use Illuminate\Contracts\Support\Responsable;

final class AskDocsController extends Controller
{
    public function __invoke(DocsAskRequest $request, DocsAnswerService $service): Responsable
    {
        return $service->ask($request->question(), $request->useCache(), $request->history());
    }
}
