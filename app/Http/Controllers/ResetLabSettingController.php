<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AiSettingsService;
use Illuminate\Http\RedirectResponse;

final class ResetLabSettingController extends Controller
{
    public function __invoke(string $feature, AiSettingsService $settings): RedirectResponse
    {
        return to_route('ai.settings')->with('settings', [
            'feature' => $feature,
            'message' => $settings->reset($feature),
        ]);
    }
}
