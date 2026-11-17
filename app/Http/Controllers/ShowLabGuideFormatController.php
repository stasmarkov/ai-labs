<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GuideFormatType;
use App\Services\GuideFormatPageService;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabGuideFormatController extends Controller
{
    /**
     * An unknown format never reaches here: the enum route binding answers 404.
     */
    public function __invoke(string $key, GuideFormatType $format, GuideFormatPageService $service): Response
    {
        return Inertia::render('Lab/GuideFormat/'.Str::studly($format->value), $service->format($key, $format));
    }
}
