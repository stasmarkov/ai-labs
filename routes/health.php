<?php

use App\Http\Controllers\HealthzController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', HealthzController::class)->name('healthz');
