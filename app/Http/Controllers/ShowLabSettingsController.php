<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AiSettingsService;
use App\Services\GuidePageService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabSettingsController extends Controller
{
    public function __invoke(Request $request, AiSettingsService $settings, GuidePageService $guides): Response
    {
        return Inertia::render('Lab/Settings', [
            'guideKey' => 'providers',
            'teach' => $guides->teach('providers'),
            'rows' => $settings->rows(),
            'flash' => $request->session()->get('settings'),
            'saveUrl' => route('ai.settings.save', ['feature' => 'FEATURE'], absolute: false),
            'resetUrl' => route('ai.settings.reset', ['feature' => 'FEATURE'], absolute: false),
            'featurePlaceholder' => 'FEATURE',
        ]);
    }
}
