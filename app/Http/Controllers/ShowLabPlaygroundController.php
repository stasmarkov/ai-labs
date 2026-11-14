<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\PlaygroundPromptSummary;
use App\DTOs\PlaygroundRunSummary;
use App\Http\Middleware\EnsureLocalLab;
use App\Services\PlaygroundPromptService;
use App\Services\PlaygroundRunner;
use App\Services\PlaygroundRunService;
use App\Services\PlaygroundTargetCatalog;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabPlaygroundController extends Controller
{
    private const ID_PLACEHOLDER = 'ITEM_ID';

    public function __invoke(PlaygroundRunService $runs, PlaygroundPromptService $prompts, PlaygroundTargetCatalog $targets, ?string $id = null): Response
    {
        $run = $id === null ? null : ($runs->find($id) ?? abort(404));

        return Inertia::render('Lab/Playground', [
            'run' => $run?->toArray(),
            'recent' => array_map(fn (PlaygroundRunSummary $item): array => $item->toArray(), $runs->recent()),
            'prompts' => array_map(fn (PlaygroundPromptSummary $item): array => $item->toArray(), $prompts->all()),
            'providers' => $targets->providers(),
            'defaultTargets' => $targets->defaultTargets(),
            'localOnlyCode' => EnsureLocalLab::CODE,
            'idPlaceholder' => self::ID_PLACEHOLDER,
            'urls' => [
                'prompts' => route('playground.prompts.index', [], false),
                'prompt' => route('playground.prompts.show', ['id' => self::ID_PLACEHOLDER], false),
                'versions' => route('playground.versions.store', ['id' => self::ID_PLACEHOLDER], false),
                'runs' => route('playground.runs.index', [], false),
                'run' => route('playground.runs.show', ['id' => self::ID_PLACEHOLDER], false),
                'page' => route('ai.playground.show', ['id' => self::ID_PLACEHOLDER], false),
                'log' => route('ai.runs.show', ['invocationId' => self::ID_PLACEHOLDER], false),
            ],
            'limits' => [
                'input' => PlaygroundRunner::MAX_INPUT_LENGTH,
                'systemPrompt' => PlaygroundRunner::MAX_SYSTEM_PROMPT_LENGTH,
                'maxTokens' => ['min' => PlaygroundRunner::MIN_MAX_TOKENS, 'max' => PlaygroundRunner::MAX_MAX_TOKENS],
                'temperature' => PlaygroundRunner::MAX_TEMPERATURE,
                'targets' => ['min' => PlaygroundRunner::MIN_TARGETS, 'max' => PlaygroundRunner::MAX_TARGETS],
            ],
        ]);
    }
}
