<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\LabOverviewService;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'ai.app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'lab' => fn (): array => app(LabOverviewService::class)->topBar(),
        ];
    }
}
