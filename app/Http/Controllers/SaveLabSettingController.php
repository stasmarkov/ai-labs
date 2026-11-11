<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveAiSettingRequest;
use App\Services\AiSettingsService;
use Illuminate\Http\RedirectResponse;

final class SaveLabSettingController extends Controller
{
    public function __invoke(SaveAiSettingRequest $request, AiSettingsService $settings): RedirectResponse
    {
        $choice = $request->toChoice();

        return to_route('ai.settings')->with('settings', [
            'feature' => $choice->feature,
            'message' => $settings->save($choice),
        ]);
    }
}
