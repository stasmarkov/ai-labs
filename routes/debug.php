<?php

use App\Http\Controllers\DebugRuntimeController;
use App\Http\Controllers\DebugSleepController;
use Illuminate\Support\Facades\Route;

Route::get('/debug/runtime', DebugRuntimeController::class)->name('debug.runtime');
Route::get('/debug/sleep/{seconds}', DebugSleepController::class)->whereNumber('seconds')->name('debug.sleep');
