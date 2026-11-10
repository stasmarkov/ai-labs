<?php

use App\Http\Controllers\ExtractLabPhotoController;
use App\Http\Controllers\ShowLabChatController;
use App\Http\Controllers\ShowLabDescribeController;
use App\Http\Controllers\ShowLabDocsController;
use App\Http\Controllers\ShowLabGuideController;
use App\Http\Controllers\ShowLabGuidesController;
use App\Http\Controllers\ShowLabHomeController;
use App\Http\Controllers\ShowLabNlSearchController;
use App\Http\Controllers\ShowLabOverviewController;
use App\Http\Controllers\ShowLabPhotosController;
use App\Http\Controllers\ShowLabPhotoStatusController;
use App\Http\Controllers\ShowLabRunController;
use App\Http\Controllers\ShowLabSemanticController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitCloudAi;
use Illuminate\Support\Facades\Route;

Route::middleware(HandleInertiaRequests::class)->group(function (): void {
    Route::get('/', ShowLabHomeController::class)->name('home');
});

Route::middleware(HandleInertiaRequests::class)->prefix('ai')->group(function (): void {
    Route::get('/', ShowLabOverviewController::class)->name('ai.overview');
    Route::get('/describe', ShowLabDescribeController::class)->name('ai.describe');
    Route::get('/semantic', ShowLabSemanticController::class)->name('ai.semantic');
    Route::get('/docs', ShowLabDocsController::class)->name('ai.docs');
    Route::get('/chat', ShowLabChatController::class)->name('ai.chat');
    Route::get('/nl-search', ShowLabNlSearchController::class)->name('ai.nl-search');
    Route::get('/photos', ShowLabPhotosController::class)->name('ai.photos');
    Route::post('/photos/{photo}/extract', ExtractLabPhotoController::class)->whereNumber('photo')->middleware(['throttle:photo-extract', LimitCloudAi::class.':photo-vision'])->name('ai.photos.extract');
    Route::get('/photos/{photo}/status', ShowLabPhotoStatusController::class)->whereNumber('photo')->name('ai.photos.status');
    Route::get('/guides', ShowLabGuidesController::class)->name('ai.guides.index');
    Route::get('/guides/{key}', ShowLabGuideController::class)->name('ai.guides.show');
    Route::get('/runs/{invocationId}', ShowLabRunController::class)->name('ai.runs.show');
});
