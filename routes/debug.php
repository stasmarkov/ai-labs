<?php

use App\Debug\Leaks\LeakScenario;
use App\Http\Controllers\DebugLeakController;
use App\Http\Controllers\DebugRuntimeController;
use App\Http\Controllers\DebugSleepController;
use App\Http\Controllers\DebugWorkerController;
use App\Http\Middleware\EnsureDebugRoutes;
use Illuminate\Support\Facades\Route;

// Registered for every method so that, while DEBUG_ROUTES is off, a POST answers the same 404 as an
// unknown path instead of a 405 that would reveal the route; EnsureDebugRoutes allows GET/HEAD when on.
Route::middleware(EnsureDebugRoutes::class)->group(function (): void {
    Route::any('/debug/runtime', DebugRuntimeController::class)->name('debug.runtime');
    Route::any('/debug/sleep/{seconds}', DebugSleepController::class)->whereNumber('seconds')->name('debug.sleep');
    Route::any('/debug/leaks/{scenario}', DebugLeakController::class)->whereIn('scenario', LeakScenario::values())->name('debug.leaks');
    Route::any('/debug/worker', DebugWorkerController::class)->name('debug.worker');
});
