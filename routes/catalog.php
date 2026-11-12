<?php

use App\Http\Controllers\AskAssistantController;
use App\Http\Controllers\AskDocsController;
use App\Http\Controllers\DescribeListingController;
use App\Http\Controllers\ListListingsController;
use App\Http\Controllers\NlSearchController;
use App\Http\Controllers\PhotoTextSearchController;
use App\Http\Controllers\SearchDocsController;
use App\Http\Controllers\SemanticSearchController;
use App\Http\Controllers\ServeExportController;
use App\Http\Controllers\ShowListingController;
use App\Http\Controllers\ShowSlowListingController;
use App\Http\Middleware\AddServerTimingHeader;
use App\Http\Middleware\EnsureDebugRoutes;
use App\Http\Middleware\LimitCloudAi;
use App\Http\Middleware\SendEarlyHints;
use App\Services\ExportFileService;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

Route::get('/listings', ListListingsController::class)->name('listings.index');
Route::get('/listings/{id}', ShowListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->middleware([SendEarlyHints::class, AddLinkHeadersForPreloadedAssets::class])
    ->name('listings.show');
Route::get('/listings/{id}/slow', ShowSlowListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->middleware([EnsureDebugRoutes::class, 'throttle:slow'])
    ->name('listings.slow');
Route::get('/listings/{id}/describe', DescribeListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->middleware(['throttle:describe', LimitCloudAi::class.':describe'])
    ->name('listings.describe');
Route::get('/api/listings/semantic', SemanticSearchController::class)
    ->middleware(['throttle:semantic-search', LimitCloudAi::class.':embeddings-listings', AddServerTimingHeader::class])
    ->name('listings.semantic');
Route::get('/api/listings/nl', NlSearchController::class)
    ->middleware(['throttle:nl-search', LimitCloudAi::class.':nl-search', AddServerTimingHeader::class])
    ->name('listings.nl');
Route::get('/api/photos/search', PhotoTextSearchController::class)
    ->middleware(['throttle:photo-search', LimitCloudAi::class.':embeddings-photos', AddServerTimingHeader::class])
    ->name('photos.search');
Route::get('/api/docs/search', SearchDocsController::class)
    ->middleware(['throttle:docs-search', LimitCloudAi::class.':embeddings-docs', AddServerTimingHeader::class])
    ->name('docs.search');
Route::post('/api/docs/ask', AskDocsController::class)
    ->middleware(['throttle:docs-ask', LimitCloudAi::class.':docs-answer,embeddings-docs', AddServerTimingHeader::class])
    ->name('docs.ask');
Route::post('/api/assistant/ask', AskAssistantController::class)
    ->middleware(['throttle:assistant-ask', LimitCloudAi::class.':assistant', AddServerTimingHeader::class])
    ->name('assistant.ask');
Route::get('/exports/{id}', ServeExportController::class)
    ->where('id', ExportFileService::ID_PATTERN)
    ->middleware([EnsureDebugRoutes::class, 'throttle:exports'])
    ->name('exports.show');
