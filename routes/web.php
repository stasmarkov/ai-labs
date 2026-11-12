<?php

use App\Ai\AiProviders;
use App\Http\Controllers\ExtractLabPhotoController;
use App\Http\Controllers\KeepDuplicatePhotoController;
use App\Http\Controllers\ResetLabSettingController;
use App\Http\Controllers\SaveLabSettingController;
use App\Http\Controllers\ShowLabAssistantController;
use App\Http\Controllers\ShowLabChatController;
use App\Http\Controllers\ShowLabDescribeController;
use App\Http\Controllers\ShowLabDocsController;
use App\Http\Controllers\ShowLabGuideController;
use App\Http\Controllers\ShowLabGuidesController;
use App\Http\Controllers\ShowLabHomeController;
use App\Http\Controllers\ShowLabModerationController;
use App\Http\Controllers\ShowLabNlSearchController;
use App\Http\Controllers\ShowLabOverviewController;
use App\Http\Controllers\ShowLabPhotoDuplicatesController;
use App\Http\Controllers\ShowLabPhotosController;
use App\Http\Controllers\ShowLabPhotoSearchController;
use App\Http\Controllers\ShowLabPhotoSimilarController;
use App\Http\Controllers\ShowLabPhotoStatusController;
use App\Http\Controllers\ShowLabRunController;
use App\Http\Controllers\ShowLabSemanticController;
use App\Http\Controllers\ShowLabSettingsController;
use App\Http\Controllers\UndoDuplicateReviewController;
use App\Http\Middleware\EnsureLocalSettings;
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
    Route::get('/assistant', ShowLabAssistantController::class)->name('ai.assistant');
    Route::get('/moderation', ShowLabModerationController::class)->name('ai.moderation');
    Route::get('/nl-search', ShowLabNlSearchController::class)->name('ai.nl-search');
    Route::get('/photo-search', ShowLabPhotoSearchController::class)->name('ai.photo-search');
    Route::get('/photos', ShowLabPhotosController::class)->name('ai.photos');
    Route::get('/photos/duplicates', ShowLabPhotoDuplicatesController::class)->name('ai.photos.duplicates');
    Route::post('/photos/duplicates/{group}/keep', KeepDuplicatePhotoController::class)->where('group', '[1-9][0-9]{0,17}')->middleware('throttle:duplicate-review')->name('ai.photos.duplicates.keep');
    Route::post('/photos/duplicates/{group}/undo', UndoDuplicateReviewController::class)->where('group', '[1-9][0-9]{0,17}')->middleware('throttle:duplicate-review')->name('ai.photos.duplicates.undo');
    Route::post('/photos/{photo}/extract', ExtractLabPhotoController::class)->whereNumber('photo')->middleware(['throttle:photo-extract', LimitCloudAi::class.':photo-vision'])->name('ai.photos.extract');
    Route::get('/photos/{photo}/status', ShowLabPhotoStatusController::class)->whereNumber('photo')->name('ai.photos.status');
    Route::get('/photos/{photo}/similar', ShowLabPhotoSimilarController::class)->whereNumber('photo')->middleware('throttle:photo-similar')->name('ai.photos.similar');
    Route::get('/guides', ShowLabGuidesController::class)->name('ai.guides.index');
    Route::get('/guides/{key}', ShowLabGuideController::class)->name('ai.guides.show');
    Route::get('/runs/{invocationId}', ShowLabRunController::class)->name('ai.runs.show');

    Route::middleware(EnsureLocalSettings::class)->prefix('settings')->where(['feature' => implode('|', array_keys(AiProviders::features()))])->group(function (): void {
        Route::get('/', ShowLabSettingsController::class)->name('ai.settings');
        Route::put('/{feature}', SaveLabSettingController::class)->name('ai.settings.save');
        Route::delete('/{feature}', ResetLabSettingController::class)->name('ai.settings.reset');
    });
});
