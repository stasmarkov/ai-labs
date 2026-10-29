<?php

use App\Http\Controllers\DebugRuntimeController;
use Illuminate\Support\Facades\Route;

Route::get('/debug/runtime', DebugRuntimeController::class)->name('debug.runtime');
