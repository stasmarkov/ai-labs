<?php

use App\Http\Controllers\ShowLabChatController;
use App\Http\Controllers\ShowLabDescribeController;
use App\Http\Controllers\ShowLabDocsController;
use App\Http\Controllers\ShowLabNlSearchController;
use App\Http\Controllers\ShowLabOverviewController;
use App\Http\Controllers\ShowLabPhotosController;
use App\Http\Controllers\ShowLabRunController;
use App\Http\Controllers\ShowLabSemanticController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(HandleInertiaRequests::class)->prefix('ai')->group(function (): void {
    Route::get('/', ShowLabOverviewController::class)->name('ai.overview');
    Route::get('/describe', ShowLabDescribeController::class)->name('ai.describe');
    Route::get('/semantic', ShowLabSemanticController::class)->name('ai.semantic');
    Route::get('/docs', ShowLabDocsController::class)->name('ai.docs');
    Route::get('/chat', ShowLabChatController::class)->name('ai.chat');
    Route::get('/nl-search', ShowLabNlSearchController::class)->name('ai.nl-search');
    Route::get('/photos', ShowLabPhotosController::class)->name('ai.photos');
    Route::get('/runs/{invocationId}', ShowLabRunController::class)->name('ai.runs.show');
});
