<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LabProviderService;
use App\Services\ResearchRunner;
use App\Services\ResearchRunService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabResearchController extends Controller
{
    private const ID_PLACEHOLDER = 'RUN_ID';

    public function __invoke(ResearchRunService $service, LabProviderService $providers, ?string $id = null): Response
    {
        $run = $id === null ? null : ($service->find($id) ?? abort(404));

        return Inertia::render('Lab/Research', [
            'run' => $run,
            'recent' => $service->recent(),
            'startUrl' => route('research.start', [], false),
            'showUrl' => route('research.show', ['id' => self::ID_PLACEHOLDER], false),
            'pageUrl' => route('ai.research.show', ['id' => self::ID_PLACEHOLDER], false),
            'runUrl' => route('ai.runs.show', ['invocationId' => self::ID_PLACEHOLDER], false),
            'idPlaceholder' => self::ID_PLACEHOLDER,
            'enabledTextProviders' => $providers->textOptions('research'),
            'limits' => ['min' => ResearchRunner::MIN_TOPIC_LENGTH, 'max' => ResearchRunner::MAX_TOPIC_LENGTH],
            'examples' => [
                'How does hybrid search combine vector and text ranking?',
                'How do queued jobs retry and fail in Laravel?',
                'What does the documentation say about cooking pasta?',
            ],
        ]);
    }
}
