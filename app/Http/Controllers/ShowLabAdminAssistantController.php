<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\AdminAssistantLoop;
use App\Ai\AiProviders;
use App\Http\Requests\AskAdminAssistantRequest;
use App\Services\GuidePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabAdminAssistantController extends Controller
{
    private const RUN_PLACEHOLDER = 'RUN_ID';

    private const ACTION_PLACEHOLDER = 'ACTIONID';

    /** The questions offered as chips on the empty page. */
    private const EXAMPLES = [
        'How many diesel BMW listings are in Lviv, and show me two of them',
        'Show me listing 12',
        'Change the title of listing 12 to "Family estate, one owner"',
        'Tag listing 12 as family-car and listing 13 as family-car, then tell me how many family-car listings exist',
    ];

    public function __invoke(GuidePageService $guides, AiProviders $providers): Response
    {
        $choice = $providers->configured(AiProviders::ADMIN_ASSISTANT);

        return Inertia::render('Lab/AdminAssistant', [
            'howItWorks' => $guides->howItWorks('admin-assistant'),
            'guideHref' => route('ai.guides.show', ['key' => 'admin-assistant'], false),
            'askUrl' => route('admin-assistant.ask', [], false),
            'confirmUrl' => route('admin-assistant.confirm', ['actionId' => self::ACTION_PLACEHOLDER], false),
            'rejectUrl' => route('admin-assistant.reject', ['actionId' => self::ACTION_PLACEHOLDER], false),
            'runUrl' => route('ai.runs.show', ['invocationId' => self::RUN_PLACEHOLDER], false),
            'idPlaceholder' => self::RUN_PLACEHOLDER,
            'actionPlaceholder' => self::ACTION_PLACEHOLDER,
            'maxQuestionLength' => AskAdminAssistantRequest::MAX_QUESTION_LENGTH,
            'maxSteps' => AdminAssistantLoop::MAX_STEPS,
            'model' => ['provider' => $choice->provider, 'name' => $choice->model],
            'examples' => self::EXAMPLES,
        ]);
    }
}
