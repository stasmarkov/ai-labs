<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Debug\Leaks\LeakLabServiceProvider;
use App\Debug\Leaks\LeakMode;
use App\Debug\Leaks\LeakProbe;
use App\Debug\Leaks\LeakProbeRunner;
use App\Debug\Leaks\LeakScenario;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DebugLeakController extends Controller
{
    public function __construct(private readonly LeakProbeRunner $runner) {}

    public function __invoke(Request $request, string $scenario): JsonResponse
    {
        abort_unless(config('app.debug_routes') === true, 404);

        $data = $request->validate([
            'dealer' => ['required', 'integer', 'min:1'],
            'locale' => ['nullable', Rule::in(['uk', 'de', 'en'])],
            'mode' => ['nullable', Rule::enum(LeakMode::class)],
            'amount' => ['nullable', 'integer', 'min:0'],
        ]);

        // The lab bindings are registered when the app boots. If DEBUG_ROUTES was switched on later, a
        // long-lived worker still runs the app booted without them: say so instead of failing obscurely.
        abort_unless(app()->bound(LeakLabServiceProvider::DEALER_LEAK), 503, 'The leak lab is not loaded in this worker. Restart the workers after enabling DEBUG_ROUTES.');

        $probe = new LeakProbe(
            dealer: (int) $data['dealer'],
            locale: $data['locale'] ?? 'en',
            mode: LeakMode::from($data['mode'] ?? 'leak'),
            amountCents: (int) ($data['amount'] ?? 7150300),
            localeGiven: isset($data['locale']),
        );

        $result = $this->runner->run(LeakScenario::from($scenario), $probe);

        return ApiResponse::success($result->toArray(), 'leak lab');
    }
}
