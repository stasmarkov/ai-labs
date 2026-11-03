<?php

use App\Http\Controllers\AskDocsController;
use App\Http\Controllers\DescribeListingController;
use App\Http\Controllers\ListListingsController;
use App\Http\Controllers\SearchDocsController;
use App\Http\Controllers\SemanticSearchController;
use App\Http\Controllers\ShowListingController;
use App\Http\Controllers\ShowSlowListingController;
use App\Http\Middleware\AddServerTimingHeader;
use App\Http\Middleware\EnsureDebugRoutes;
use Illuminate\Support\Facades\Route;

Route::get('/listings', ListListingsController::class)->name('listings.index');
Route::get('/listings/{id}', ShowListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->name('listings.show');
Route::get('/listings/{id}/slow', ShowSlowListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->middleware([EnsureDebugRoutes::class, 'throttle:slow'])
    ->name('listings.slow');
Route::get('/listings/{id}/describe', DescribeListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->middleware('throttle:describe')
    ->name('listings.describe');
Route::get('/api/listings/semantic', SemanticSearchController::class)
    ->middleware(['throttle:semantic-search', AddServerTimingHeader::class])
    ->name('listings.semantic');
Route::get('/api/docs/search', SearchDocsController::class)
    ->middleware(['throttle:docs-search', AddServerTimingHeader::class])
    ->name('docs.search');
Route::post('/api/docs/ask', AskDocsController::class)
    ->middleware(['throttle:docs-ask', AddServerTimingHeader::class])
    ->name('docs.ask');
