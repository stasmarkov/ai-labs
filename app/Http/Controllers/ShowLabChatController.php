<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DocsAskRequest;
use App\Services\GuidePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabChatController extends Controller
{
    private const ID_PLACEHOLDER = 'RUN_ID';

    public function __invoke(GuidePageService $guides): Response
    {
        return Inertia::render('Lab/Chat', [
            'howItWorks' => $guides->howItWorks('chat'),
            'guideHref' => route('ai.guides.show', ['key' => 'chat'], false),
            'askUrl' => route('docs.ask', [], false),
            'runUrl' => route('ai.runs.show', ['invocationId' => self::ID_PLACEHOLDER], false),
            'idPlaceholder' => self::ID_PLACEHOLDER,
            'maxQuestionLength' => DocsAskRequest::MAX_QUESTION_LENGTH,
            'maxTurnLength' => DocsAskRequest::MAX_TURN_LENGTH,
            'maxHistoryTurns' => (int) config('ai.docs.chat.max_history_turns'),
        ]);
    }
}
